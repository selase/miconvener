<?php

declare(strict_types=1);

namespace App\Services\Marketplace;

use App\Models\LedgerTransaction;
use App\Models\StoreOrder;
use App\Models\StorePaymentObservation;
use App\Models\StoreRefund;
use App\Models\User;
use App\Services\Finance\LedgerService;
use App\Support\WithoutTelescope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

final class StoreRefunds
{
    public function __construct(private readonly CommerceOrderLocks $locks, private readonly StoreOrderAccess $access, private readonly StoreBalance $balance, private readonly StoreGateways $gateways, private readonly LedgerService $ledger, private readonly CommerceOfferingRegistry $offerings) {}

    public function request(StoreOrder $order, User $actor, string $operationKey, string $reason, ?string $observationId = null): StoreRefund
    {
        $this->authorize($order, $actor);
        $reason = mb_trim($reason);
        if (! Str::isUuid($operationKey) || $reason === '' || mb_strlen($reason) > 500) {
            $this->refuse('Provide a reason and a valid refund operation key.');
        }
        $refund = $order->getConnection()->transaction(function () use ($order, $actor, $operationKey, $reason, $observationId): StoreRefund {
            $order = $this->locks->order($order);
            $this->authorize($order, $actor);
            $this->offerings->for($order)->assertRefund($order);
            $prior = StoreRefund::query()->where('tenant_id', $order->tenant_id)->where('operation_key', $operationKey)->first();
            if ($prior !== null) {
                if ($prior->order_id !== $order->id || $prior->reason !== $reason || ($observationId !== null && $prior->observation_id !== $observationId)) {
                    $this->refuse('This operation key belongs to another refund request.');
                }

                return $prior;
            }
            $query = StorePaymentObservation::query()->where('order_id', $order->id)->whereNotNull('ledger_transaction_id');
            $capture = $observationId === null ? $query->where('processing_state', 'posted')->first() : $query->find($observationId);
            if ($capture === null || ! $capture->account_matches || ! $capture->reference_matches || $capture->currency !== 'GHS' || $capture->amount_pesewas === null || $capture->gateway_fee_pesewas === null) {
                $this->refuse('Resolve the captured payment accounting before requesting a refund.');
            }
            $booked = StorePaymentObservation::query()->where('attempt_id', $capture->attempt_id)->where('ledger_transaction_id', $capture->ledger_transaction_id)->orderBy('observation_version')->first();
            if ($booked === null || $booked->amount_pesewas !== $capture->amount_pesewas || $booked->gateway_fee_pesewas !== $capture->gateway_fee_pesewas) {
                $this->refuse('Changed financial evidence needs reconciliation before refunding.');
            }
            $existing = StoreRefund::query()->where('attempt_id', $capture->attempt_id)->first();
            if ($existing !== null) {
                return $existing;
            }
            $txn = LedgerTransaction::withoutGlobalScopes()->where('tenant_id', $order->tenant_id)->where('reference', $capture->reference)->where('category', 'commerce')->findOrFail($capture->ledger_transaction_id);
            $sale = $txn->transaction_type === 'commerce_sale';
            if ((! $sale && $txn->transaction_type !== 'commerce_capture_review') || ($sale && ($order->ledger_state === 'review' || in_array($order->fulfilment_state, ['collected', 'delivered', 'dispatched'], true) || $order->payment_state !== 'paid'))) {
                $this->refuse('Collected or unreconciled orders require an approved support review.');
            }
            $bearer = $order->terms_snapshot['refund_gateway_fee_bearer'] ?? null;
            if (! in_array($bearer, ['seller', 'platform'], true)) {
                $this->refuse('Refund funding ownership must be approved before a refund.');
            }
            $amount = $capture->amount_pesewas;
            $commission = $sale ? $order->platform_fee_pesewas : 0;
            $sellerDebit = $sale ? $amount - $commission - ($bearer === 'platform' ? $capture->gateway_fee_pesewas : 0) : ($bearer === 'seller' ? $capture->gateway_fee_pesewas : 0);
            $refund = StoreRefund::query()->create(['order_id' => $order->id, 'tenant_id' => $order->tenant_id, 'shop_id' => $order->shop_id,
                'attempt_id' => $capture->attempt_id, 'observation_id' => $capture->id, 'operation_key' => $operationKey, 'reason' => $reason,
                'requested_by' => $actor->id, 'account_fingerprint' => $capture->account_fingerprint, 'amount_pesewas' => $amount,
                'seller_debit_pesewas' => $sellerDebit, 'commission_return_pesewas' => $commission, 'gateway_fee_pesewas' => $capture->gateway_fee_pesewas,
                'review_liability_pesewas' => $sale ? 0 : $amount, 'fee_bearer' => $bearer, 'restock' => $sale, 'status' => 'needs_funding']);
            if ($sale) {
                app(StoreCollectionPasses::class)->revoke($order, 'primary_refund');
                $order->update(['payment_state' => 'refund_pending']);
            }

            return $refund;
        });

        return $refund->submitted_at === null && $refund->status === 'needs_funding' ? $this->submitWhenBacked($refund) : $refund;
    }

    public function cancelFree(StoreOrder $order, User $actor, string $operationKey, string $reason): StoreRefund
    {
        $this->authorize($order, $actor);
        $reason = mb_trim($reason);
        if (! Str::isUuid($operationKey) || $reason === '' || mb_strlen($reason) > 500) {
            $this->refuse('Provide a reason and a valid cancellation key.');
        }

        return $order->getConnection()->transaction(function () use ($order, $actor, $operationKey, $reason): StoreRefund {
            $order = $this->locks->order($order);
            $this->authorize($order, $actor);
            $this->offerings->for($order)->assertRefund($order);
            $prior = StoreRefund::query()->where('order_id', $order->id)->whereNull('attempt_id')->first();
            if ($prior !== null) {
                if ($prior->operation_key !== $operationKey || $prior->reason !== $reason) {
                    $this->refuse('This free order was already cancelled.');
                }

                return $prior;
            }
            if ($order->charged_pesewas !== 0 || $order->state !== 'confirmed' || $order->payment_state !== 'not_required' || in_array($order->fulfilment_state, ['collected', 'delivered', 'dispatched'], true)) {
                $this->refuse('Only a confirmed, uncollected free order can be cancelled without a refund.');
            }
            $refund = StoreRefund::query()->create(['order_id' => $order->id, 'tenant_id' => $order->tenant_id, 'shop_id' => $order->shop_id,
                'operation_key' => $operationKey, 'reason' => $reason, 'requested_by' => $actor->id, 'account_fingerprint' => $order->account_fingerprint,
                'amount_pesewas' => 0, 'seller_debit_pesewas' => 0, 'commission_return_pesewas' => 0, 'gateway_fee_pesewas' => 0, 'review_liability_pesewas' => 0,
                'fee_bearer' => 'seller', 'restock' => true, 'status' => 'confirmed', 'provider_status' => 'not_required']);
            app(StoreCollectionPasses::class)->revoke($order, 'free_cancel');
            app(MerchandiseInventory::class)->restockRefund($refund);
            $order->update(['state' => 'cancelled', 'payment_state' => 'refunded', 'fulfilment_state' => 'cancelled']);

            return $refund;
        });
    }

    /** Called only after the original platform webhook signature matched.
     * @param  array<string, mixed>  $data
     */
    public function paystack(array $data, string $accountIdentity): bool
    {
        $note = $data['merchant_note'] ?? null;
        if (! is_string($note) || ! str_starts_with($note, 'store:')) {
            return false;
        }
        $refund = StoreRefund::query()->where('operation_key', mb_substr($note, 6))->first();
        if ($refund !== null && hash_equals($refund->account_fingerprint, hash('sha256', $accountIdentity))) {
            $this->reconcile($refund, isset($data['id']) ? (string) $data['id'] : null);
        }

        return true;
    }

    public function reconcile(StoreRefund $refund, ?string $candidateId = null): StoreRefund
    {
        $refund = $refund->fresh();
        if ($refund->status === 'confirmed') {
            return $refund;
        }
        if ($refund->submitted_at === null) {
            return $this->submitWhenBacked($refund);
        }
        if ($refund->provider_processed_at !== null) {
            return $this->complete($refund);
        }
        try {
            [$id, $data] = WithoutTelescope::run(function () use ($refund, $candidateId): array {
                $gateway = $this->gateways->forAttempt($refund->attempt);
                $id = $refund->provider_refund_id ?? $candidateId ?? $gateway->findCommerceRefund($refund->attempt->reference, $refund->operation_key, $refund->amount_pesewas, 'GHS');

                return [$id, $id === null ? ['status' => 'unknown'] : $gateway->verifyCommerceRefund($id, $refund->attempt->reference, $refund->operation_key)];
            });
        } catch (Throwable) {
            StoreRefund::query()->whereKey($refund->id)->where('status', '<>', 'confirmed')->whereNull('provider_processed_at')->update(['status' => 'unknown', 'review_reason' => 'refund_verification_unavailable']);

            return $refund->fresh();
        }
        $refund = $refund->getConnection()->transaction(function () use ($refund, $id, $data): StoreRefund {
            $this->locks->order($refund->order);
            $current = StoreRefund::query()->lockForUpdate()->findOrFail($refund->id);
            if ($current->status === 'confirmed' || $current->provider_processed_at !== null) {
                return $current;
            }
            if ($id === null) {
                $current->update(['status' => 'unknown', 'review_reason' => 'refund_outcome_unknown']);
            } elseif (($data['amount'] ?? null) !== $current->amount_pesewas || ($data['currency'] ?? null) !== 'GHS' || ($data['payment_reference'] ?? null) !== $current->attempt->reference) {
                $current->update(['status' => 'review', 'review_reason' => 'refund_verification_mismatch']);
            } elseif (($data['status'] ?? null) === 'processed') {
                $current->update(['provider_refund_id' => $id, 'provider_status' => 'processed', 'provider_processed_at' => now()]);
            } else {
                $current->update(['provider_refund_id' => $id, 'provider_status' => mb_substr((string) ($data['status'] ?? 'unknown'), 0, 30),
                    'status' => in_array($data['status'] ?? null, ['pending', 'processing'], true) ? 'pending' : 'review', 'review_reason' => 'provider_refund_not_complete']);
            }

            return $current;
        });
        if ($refund->provider_processed_at === null || $refund->status === 'confirmed') {
            return $refund;
        }

        return $this->complete($refund);
    }

    private function submitWhenBacked(StoreRefund $refund): StoreRefund
    {
        $claimed = $refund->getConnection()->transaction(function () use ($refund): bool {
            $this->locks->order($refund->order);
            $locked = StoreRefund::query()->lockForUpdate()->findOrFail($refund->id);
            if ($locked->submitted_at !== null || $locked->status === 'confirmed' || ! $this->balance->refundBacked($locked)) {
                return false;
            }
            $this->gateways->forAttempt($locked->attempt);
            $locked->update(['status' => 'submitting', 'submitted_at' => now()]);

            return true;
        });
        if (! $claimed) {
            return $refund->fresh();
        }
        try {
            $result = WithoutTelescope::run(fn () => $this->gateways->forAttempt($refund->attempt)->requestCommerceRefund($refund->attempt->reference, $refund->amount_pesewas, $refund->operation_key));
            StoreRefund::query()->whereKey($refund->id)->where('status', 'submitting')->update(['status' => filled($result['reference']) ? 'pending' : 'unknown', 'provider_refund_id' => filled($result['reference']) ? $result['reference'] : null, 'provider_status' => $result['status']]);
        } catch (Throwable) {
            StoreRefund::query()->whereKey($refund->id)->where('status', 'submitting')->update(['status' => 'unknown', 'review_reason' => 'refund_submission_unknown']);
        }

        return $refund->fresh();
    }

    private function complete(StoreRefund $refund): StoreRefund
    {
        return $refund->getConnection()->transaction(function () use ($refund): StoreRefund {
            $order = $this->locks->order($refund->order);
            $refund = StoreRefund::query()->lockForUpdate()->findOrFail($refund->id);
            if ($refund->status === 'confirmed') {
                return $refund;
            }
            $transaction = $this->ledger->recordStoreRefund($refund);
            $refund->update(['status' => 'confirmed', 'ledger_transaction_id' => $transaction->id, 'review_reason' => in_array($order->fulfilment_state, ['collected', 'delivered', 'dispatched'], true) && $refund->restock ? match ($order->fulfilment_state) {
                'dispatched' => 'goods_in_transit', 'delivered' => 'goods_already_delivered', default => 'goods_already_collected'
            } : null]);
            $this->offerings->for($order)->applyRefund($refund, $order);

            return $refund;
        });
    }

    private function authorize(StoreOrder $order, User $actor): void
    {
        if (! $this->access->staff($order, $order->tenant, $actor, 'manage store finance')) {
            throw new AuthorizationException;
        }
    }

    private function refuse(string $message): never
    {
        throw ValidationException::withMessages(['refund' => $message]);
    }
}
