<?php

declare(strict_types=1);

namespace App\Services\Marketplace;

use App\Http\Controllers\Billing\WebhookController;
use App\Models\StoreOrder;
use App\Models\StorePaymentAttempt;
use App\Models\StoreStockAllocation;
use App\Models\Tenant;
use App\Support\WithoutTelescope;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

final class StorePayments
{
    public function __construct(private readonly CommerceInventoryLocks $locks, private readonly StoreOrderAccess $access, private readonly StoreGateways $gateways) {}

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
            if ($order->state === 'quoted' && ! \App\Models\StoreFulfilmentArrangement::query()->where('order_id', $order->id)->exists()) {
                $this->refuse();
            }
            $seller = Tenant::query()->findOrFail($order->tenant_id);
            if (! app(CommerceCatalogRollout::class)->enabled($seller)
                || ! $seller->features()->where('feature_key', 'commerce_checkout')->where('enabled', true)->exists()
                || ! $seller->isPlatformDefaultSettlement() || $order->expires_at->lessThanOrEqualTo(now())) {
                $this->refuse();
            }
            $sellerQuote = $order->sellerQuote()->first();
            if ($sellerQuote !== null) {
                if ($acceptedQuoteHash === null || ! hash_equals($order->terms_hash, $acceptedQuoteHash) || ! app(StoreSellerQuotes::class)->validCommitment($order)) {
                    $this->refuse();
                }
                if ($order->accepted_at === null) {
                    foreach ($order->lines()->with('variant.merchandise')->get() as $line) {
                        if ($line->variant->merchandise->visibility === 'members' && ! app(MerchandiseAccess::class)->canReadMembersFrom($seller, $session)) {
                            $this->refuse();
                        }
                    }
                }
            }
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
            if ($sellerQuote !== null) {
                if (StoreStockAllocation::query()->where('order_id', $order->id)->exists()) {
                    $this->refuse();
                }
                $variantIds = $order->lines()->pluck('variant_id')->all();
            } else {
                $allocations = StoreStockAllocation::query()->where('order_id', $order->id)->get();
                if ($allocations->isEmpty() || $allocations->count() !== $order->lines()->count() || $allocations->contains(fn (StoreStockAllocation $a): bool => $a->state !== 'active' || $a->expires_at->lessThanOrEqualTo(now()))) {
                    $this->refuse();
                }
                $variantIds = $allocations->pluck('variant_id')->all();
            }
            $offered = \App\Models\StoreVariant::query()->whereKey($variantIds)->where('is_active', true)
                ->whereHas('merchandise', fn ($query) => $query->whereNull('archived_at')->whereHas('listing', fn ($listing) => $listing->withoutGlobalScope(\App\Scopes\MerchandiseVisibilityScope::class)->where('status', \App\Models\StoreListing::STATUS_PUBLISHED)->whereNull('taken_down_at')))->count();
            if ($offered !== count($variantIds)) {
                $this->refuse();
            }
            if ($order->charged_pesewas === 0) {
                $order->update(['accepted_at' => now(), 'payment_state' => 'not_required', 'ledger_state' => 'not_applicable']);
                app(PurchaseReservations::class)->consume($order);
                $order->update(['state' => 'confirmed']);
                app(StoreReceipts::class)->capture($order);

                return null;
            }
            $order->update(['state' => 'awaiting_payment', 'accepted_at' => $order->accepted_at ?? now()]);
            $created = true;

            return StorePaymentAttempt::query()->create(['order_id' => $order->id, 'tenant_id' => $order->tenant_id, 'shop_id' => $order->shop_id,
                'submission_key' => $submissionKey, 'reference' => 'store-'.Str::uuid(), 'provider' => $order->provider,
                'settlement_mode' => $order->settlement_mode, 'account_fingerprint' => $order->account_fingerprint,
                'amount_pesewas' => $order->charged_pesewas, 'currency' => $order->currency]);
        });
        if ($attempt === null || ! $created) {
            return $attempt;
        }
        try {
            $order = $attempt->order;
            $url = WithoutTelescope::run(fn () => $this->gateways->forAttempt($attempt)->createStoreCheckoutSession(
                $order->buyer_email, $attempt->amount_pesewas, $attempt->currency,
                route('store.checkout.callback', ['reference' => $attempt->reference]),
                ['source' => 'miconvener_store', 'store_order_id' => $order->id, 'store_attempt_id' => $attempt->id, 'seller_id' => $order->tenant_id],
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
