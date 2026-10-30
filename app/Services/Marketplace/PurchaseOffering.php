<?php

declare(strict_types=1);

namespace App\Services\Marketplace;

use App\Contracts\CommerceOffering;
use App\Models\StoreFulfilmentArrangement;
use App\Models\StoreListing;
use App\Models\StoreOrder;
use App\Models\StorePaymentAttempt;
use App\Models\StorePaymentObservation;
use App\Models\StoreRefund;
use App\Models\StoreStockAllocation;
use App\Models\StoreVariant;
use App\Models\Tenant;
use App\Scopes\MerchandiseVisibilityScope;
use App\Services\Finance\LedgerService;
use Illuminate\Contracts\Session\Session;
use Illuminate\Validation\ValidationException;

/**
 * The rules for buying goods: a collection or delivery arrangement, published
 * stock held for the buyer, production quotes, and restocking on refund. These
 * were the shared payment services' own steps before venues shared them.
 */
final class PurchaseOffering implements CommerceOffering
{
    public function __construct(private readonly CommerceInventoryLocks $locks, private readonly LedgerService $ledger) {}

    public function assertCheckout(StoreOrder $order, Session $session, ?string $acceptedHash): void
    {
        if ($order->state === 'quoted' && ! StoreFulfilmentArrangement::query()->where('order_id', $order->id)->exists()) {
            $this->refuse();
        }
        $seller = Tenant::query()->findOrFail($order->tenant_id);
        if (! app(CommerceCatalogRollout::class)->enabled($seller)
            || ! $seller->features()->where('feature_key', 'commerce_checkout')->where('enabled', true)->exists()) {
            $this->refuse();
        }
        $sellerQuote = $order->sellerQuote()->first();
        if ($sellerQuote !== null) {
            if ($acceptedHash === null || ! hash_equals($order->terms_hash, $acceptedHash) || ! app(StoreSellerQuotes::class)->validCommitment($order)) {
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
    }

    public function assertNewAttempt(StoreOrder $order): void
    {
        if ($order->sellerQuote()->first() !== null) {
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
        $offered = StoreVariant::query()->whereKey($variantIds)->where('is_active', true)
            ->whereHas('merchandise', fn ($query) => $query->whereNull('archived_at')->whereHas('listing', fn ($listing) => $listing->withoutGlobalScope(MerchandiseVisibilityScope::class)->where('status', StoreListing::STATUS_PUBLISHED)->whereNull('taken_down_at')))->count();
        if ($offered !== count($variantIds)) {
            $this->refuse();
        }
    }

    public function settleWithoutPayment(StoreOrder $order): void
    {
        $order->update(['accepted_at' => now(), 'payment_state' => 'not_required', 'ledger_state' => 'not_applicable']);
        app(PurchaseReservations::class)->consume($order);
        $order->update(['state' => 'confirmed']);
        app(StoreReceipts::class)->capture($order);
    }

    public function captureIssues(StoreOrder $order, StorePaymentObservation $capture): array
    {
        $issues = [];
        $allocations = StoreStockAllocation::query()->where('order_id', $order->id)->orderBy('variant_id')->get();
        $variants = $this->locks->variants($allocations->pluck('variant_id')->all())->keyBy('id');
        if ($order->sellerQuote()->exists()) {
            if (! app(StoreSellerQuotes::class)->validCommitment($order) || $order->expires_at->lessThanOrEqualTo(now()) || $allocations->isNotEmpty()) {
                $issues[] = 'production_quote_unavailable';
            }
        } else {
            if ($allocations->isEmpty() || $allocations->count() !== $order->lines()->count() || $allocations->contains(fn (StoreStockAllocation $a): bool => $a->state !== 'active' || $a->expires_at->lessThanOrEqualTo(now()) || $variants->get($a->variant_id)?->on_hand < $a->quantity)) {
                $issues[] = 'allocation_unavailable';
            }
        }

        return $issues;
    }

    public function onCaptureReview(StoreOrder $order): void
    {
        app(PurchaseReservations::class)->release($order, 'payment_review');
    }

    public function applyCapture(StoreOrder $order, StorePaymentAttempt $attempt, StorePaymentObservation $capture): void
    {
        $transaction = $this->ledger->recordStoreSale($order, $attempt, ['observation_id' => $capture->id]);
        $capture->update(['ledger_transaction_id' => $transaction->id]);
        $order->update(['state' => 'confirmed', 'payment_state' => 'paid', 'ledger_state' => 'posted', 'paid_at' => $capture->paid_at, 'review_reason' => null]);
        app(MerchandiseInventory::class)->consumeOrder($order);
        $capture->update(['processing_state' => 'posted', 'review_reason' => null]);
        $attempt->update(['status' => 'succeeded', 'last_verified_at' => now()]);
        app(StoreReceipts::class)->capture($order);
    }

    public function expectedMetadata(StorePaymentAttempt $attempt): array
    {
        return ['source' => 'miconvener_store', 'store_order_id' => $attempt->order_id, 'store_attempt_id' => $attempt->id, 'seller_id' => $attempt->tenant_id];
    }

    public function assertRefund(StoreOrder $order): void {}

    public function applyRefund(StoreRefund $refund, StoreOrder $order): void
    {
        if ($refund->restock) {
            $order->update(['payment_state' => 'refunded', 'state' => 'cancelled']);
            if (! in_array($order->fulfilment_state, ['collected', 'delivered', 'dispatched'], true)) {
                app(MerchandiseInventory::class)->restockRefund($refund);
                $order->update(['fulfilment_state' => 'cancelled']);
            }
        }

        if (! $refund->restock && $order->payment_state === 'captured_review') {
            $order->update(['state' => 'cancelled', 'payment_state' => 'refunded', 'fulfilment_state' => 'cancelled', 'ledger_state' => 'posted', 'review_reason' => null]);
        }
    }

    public function receiptContext(StoreOrder $order): array
    {
        return app(StoreOrderPayload::class)->buyer($order);
    }

    private function refuse(): never
    {
        throw ValidationException::withMessages(['checkout' => 'Verify the original buyer email and use an available, unexpired store quote.']);
    }
}
