<?php

declare(strict_types=1);

namespace App\Services\Marketplace;

use App\Http\Controllers\Billing\WebhookController;
use App\Models\StoreOrder;
use App\Models\StorePaymentAttempt;
use App\Models\Tenant;
use App\Support\WithoutTelescope;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

final class StorePayments
{
    public function __construct(private readonly CommerceOrderLocks $locks, private readonly StoreOrderAccess $access, private readonly StoreGateways $gateways, private readonly CommerceOfferingRegistry $offerings, private readonly CommercePaymentReferences $references) {}

    public function start(StoreOrder $order, Session $session, string $submissionKey, ?string $acceptedQuoteHash = null): ?StorePaymentAttempt
    {
        $created = false;
        $attempt = $order->getConnection()->transaction(function () use ($order, $session, $submissionKey, $acceptedQuoteHash, &$created): ?StorePaymentAttempt {
            $order = $this->locks->order($order);
            if (! Str::isUuid($submissionKey) || ! $this->access->buyer($order, $session)) {
                $this->refuse();
            }
            if ($order->state === 'confirmed' && $order->payment_state === 'not_required') {
                return null;
            }
            if ($order->state === 'review' || $order->payment_state === 'captured_review') {
                $this->refuse();
            }
            $seller = Tenant::query()->findOrFail($order->tenant_id);
            if (! $seller->isPlatformDefaultSettlement() || $order->expires_at->lessThanOrEqualTo(now())) {
                $this->refuse();
            }
            $offering = $this->offerings->for($order);
            $offering->assertCheckout($order, $session, $acceptedQuoteHash);
            $this->gateways->forOrder($order);
            $prior = StorePaymentAttempt::query()->where('order_id', $order->id)->where('submission_key', $submissionKey)->first();
            if ($prior !== null) {
                return $prior;
            }
            $active = StorePaymentAttempt::query()->where('order_id', $order->id)->whereNotIn('status', ['failed', 'expired'])->latest()->first();
            if ($active !== null) {
                return $active;
            }
            if (! in_array($order->state, ['quoted', 'awaiting_payment'], true)) {
                $this->refuse();
            }
            $offering->assertNewAttempt($order);
            if ($order->charged_pesewas === 0) {
                $offering->settleWithoutPayment($order);

                return null;
            }
            $order->update(['state' => 'awaiting_payment', 'accepted_at' => $order->accepted_at ?? now()]);
            $created = true;

            $attempt = StorePaymentAttempt::query()->create(['order_id' => $order->id, 'tenant_id' => $order->tenant_id, 'shop_id' => $order->shop_id,
                'submission_key' => $submissionKey, 'reference' => 'store-'.Str::uuid(), 'provider' => $order->provider,
                'settlement_mode' => $order->settlement_mode, 'account_fingerprint' => $order->account_fingerprint,
                'amount_pesewas' => $order->charged_pesewas, 'currency' => $order->currency]);
            $this->references->claimForOrder($order, $attempt->reference);

            return $attempt;
        });
        if ($attempt === null || ! $created) {
            return $attempt;
        }
        try {
            $order = $attempt->order;
            $url = WithoutTelescope::run(fn () => $this->gateways->forAttempt($attempt)->createStoreCheckoutSession(
                $order->buyer_email, $attempt->amount_pesewas, $attempt->currency,
                route('store.checkout.callback', ['reference' => $attempt->reference]),
                $this->offerings->for($order)->expectedMetadata($attempt),
                $attempt->reference,
            ));
            $attempt->getConnection()->transaction(function () use ($attempt, $url): void {
                $this->locks->order($attempt->order);
                $current = StorePaymentAttempt::query()->lockForUpdate()->findOrFail($attempt->id);
                if ($current->status === 'initializing') {
                    $current->update(['status' => 'ready', 'checkout_url' => $url]);
                }
            });
        } catch (Throwable) {
            $attempt->getConnection()->transaction(function () use ($attempt): void {
                $this->locks->order($attempt->order);
                StorePaymentAttempt::query()->whereKey($attempt->id)->where('status', 'initializing')->update(['status' => 'unknown', 'review_reason' => 'initialization_unknown']);
            });
        }

        return $attempt->fresh();
    }

    /** @return array<string, mixed> */
    public function verify(StorePaymentAttempt $attempt): array
    {
        $attempt = StorePaymentAttempt::query()->with('order')->findOrFail($attempt->id);
        try {
            $data = WithoutTelescope::run(fn () => $this->gateways->forAttempt($attempt)->verifyTransaction($attempt->reference, true));
        } catch (Throwable) {
            throw new RuntimeException('Original store payment verification is unavailable. Reconcile this attempt without paying again.');
        }

        return ['status' => $data['status'] ?? 'pending', 'reference' => $data['reference'] ?? null,
            'reference_reported' => $data['reference_reported'] ?? false, 'provider' => 'paystack',
            'account_fingerprint' => $attempt->account_fingerprint, 'amount' => $data['amount'] ?? null,
            'currency' => ($data['currency_reported'] ?? false) ? mb_strtoupper((string) ($data['currency'] ?? '')) : '', 'fees' => ($data['fees_valid'] ?? false) ? ($data['fees'] ?? null) : null,
            'paid_at' => $data['paid_at'] ?? null, 'metadata' => WebhookController::metadata($data['metadata'] ?? null),
            'transaction_id' => $data['transaction_id'] ?? null];
    }

    private function refuse(): never
    {
        throw ValidationException::withMessages(['checkout' => 'Verify the original buyer email and use an available, unexpired store quote.']);
    }
}
