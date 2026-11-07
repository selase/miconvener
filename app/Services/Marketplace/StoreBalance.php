<?php

declare(strict_types=1);

namespace App\Services\Marketplace;

use App\Enum\LedgerCategoryEnum;
use App\Models\EventPayout;
use App\Models\LedgerAccount;
use App\Models\StoreCommercialAgreement;
use App\Models\StoreOrder;
use App\Models\StoreRefund;
use App\Models\Tenant;
use App\Models\VenueSettlementReserve;

final class StoreBalance
{
    public const array HELD_PAYOUTS = [EventPayout::STATUS_SCHEDULED, EventPayout::STATUS_AWAITING_OTP, EventPayout::STATUS_PROCESSING];

    /** Seller earnings and real category cash independently bound withdrawals.
     * Buyer refund liabilities never finance seller payouts. Refunds may use the
     * approved reserve floor; payouts must leave that protection in place.
     */
    public function available(Tenant $seller, ?string $excludePayout = null, ?string $excludeRefund = null, bool $forRefund = false): int
    {
        if (StoreOrder::query()->where('tenant_id', $seller->id)->where('ledger_state', 'review')->where(fn ($query) => $query->whereIn('payment_state', ['paid', 'refund_pending'])->orWhere('review_reason', 'verification_changed'))->exists()) {
            return 0;
        }
        $payouts = EventPayout::withoutGlobalScopes()->where('tenant_id', $seller->id)->where('category', LedgerCategoryEnum::Commerce)->where('currency', 'GHS')->whereIn('status', self::HELD_PAYOUTS);
        if ($excludePayout !== null) {
            $payouts->whereKeyNot($excludePayout);
        }
        $refunds = StoreRefund::query()->where('tenant_id', $seller->id)->where('status', '<>', 'confirmed');
        if ($excludeRefund !== null) {
            $refunds->whereKeyNot($excludeRefund);
        }
        $inFlight = (int) $payouts->sum('amount');
        $refundRows = $refunds->get();
        $reserved = $inFlight + (int) $refundRows->sum('seller_debit_pesewas') + ($forRefund ? 0 : $this->venueReserve($seller));
        $refundCash = (int) $refundRows->sum(fn (StoreRefund $refund): int => max(0, $refund->amount_pesewas - $refund->review_liability_pesewas));
        $floor = $forRefund ? 0 : $this->holdback($seller);
        $payable = $this->account($seller, LedgerAccount::CODE_ORGANIZER_PAYABLE) - $reserved - $floor;
        $cash = $this->account($seller, LedgerAccount::CODE_GATEWAY_CLEARING) + $this->account($seller, LedgerAccount::CODE_BANK)
            - $this->account($seller, LedgerAccount::CODE_BUYER_REFUNDS) - $this->account($seller, LedgerAccount::CODE_SECURITY_DEPOSITS) - $this->account($seller, LedgerAccount::CODE_HOLDBACK_RESERVE)
            - $inFlight - $refundCash - $floor;

        return max(0, min($payable, $cash));
    }

    public function cashBuffer(Tenant $seller, ?string $excludeRefund = null): int
    {
        $held = StoreRefund::query()->where('tenant_id', $seller->id)->where('status', '<>', 'confirmed');
        if ($excludeRefund !== null) {
            $held->whereKeyNot($excludeRefund);
        }
        $cost = $held->get()->sum(fn (StoreRefund $refund): int => max(0, $refund->amount_pesewas - $refund->seller_debit_pesewas - $refund->review_liability_pesewas));

        return $this->account($seller, LedgerAccount::CODE_GATEWAY_CLEARING) + $this->account($seller, LedgerAccount::CODE_BANK)
            - $this->account($seller, LedgerAccount::CODE_ORGANIZER_PAYABLE) - $this->account($seller, LedgerAccount::CODE_BUYER_REFUNDS)
            - $this->account($seller, LedgerAccount::CODE_SECURITY_DEPOSITS) - $this->account($seller, LedgerAccount::CODE_HOLDBACK_RESERVE) - $cost;
    }

    public function refundBacked(StoreRefund $refund): bool
    {
        $seller = $refund->order->tenant;
        $bufferRequired = $refund->amount_pesewas - $refund->seller_debit_pesewas - $refund->review_liability_pesewas;

        return $refund->seller_debit_pesewas <= $this->available($seller, excludeRefund: $refund->id, forRefund: true)
            && $this->cashBuffer($seller, $refund->id) >= $bufferRequired;
    }

    /**
     * Rental earnings held until the event is complete. The reserve is an overlay on
     * the payable, never a second journal, and ends with its charge's refund.
     */
    private function venueReserve(Tenant $seller): int
    {
        return (int) VenueSettlementReserve::query()->where('tenant_id', $seller->id)->where('state', 'held')
            ->whereHas('charge.order', fn ($order) => $order->whereIn('payment_state', ['paid', 'refund_pending']))->sum('reserved_pesewas');
    }

    private function account(Tenant $seller, string $code): int
    {
        return LedgerAccount::withoutGlobalScopes()->where('tenant_id', $seller->id)->where('category', LedgerCategoryEnum::Commerce)
            ->whereNull('event_id')->where('currency', 'GHS')->where('code', $code)->first()?->currentBalance() ?? 0;
    }

    private function holdback(Tenant $seller): int
    {
        $current = StoreCommercialAgreement::query()->where('tenant_id', $seller->id)->where('offering_scope', StoreCommercialAgreement::SCOPE_PURCHASE)->orderByDesc('version')->first();
        $minimum = (int) ($current?->payout_policy['holdback_pesewas'] ?? 0);
        $open = StoreOrder::query()->where('tenant_id', $seller->id)->whereIn('payment_state', ['paid', 'refund_pending'])->whereNotIn('fulfilment_state', ['collected', 'delivered'])
            ->selectRaw("max((terms_snapshot->'payout_policy'->>'holdback_pesewas')::bigint) as reserve_floor")->first();

        return max($minimum, (int) ($open?->getAttribute('reserve_floor') ?? 0));
    }
}
