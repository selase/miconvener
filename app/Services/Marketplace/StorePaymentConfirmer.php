<?php

declare(strict_types=1);

namespace App\Services\Marketplace;

use App\Models\StoreOrder;
use App\Models\StorePaymentAttempt;
use App\Models\StorePaymentObservation;
use App\Models\StoreStockAllocation;
use App\Services\Finance\LedgerService;

final class StorePaymentConfirmer
{
    public function __construct(private readonly CommerceInventoryLocks $locks, private readonly LedgerService $ledger) {}

    public function confirm(StorePaymentObservation $capture): StoreOrder
    {
        return $capture->getConnection()->transaction(function () use ($capture): StoreOrder {
            $order = $this->locks->order($capture->order);
            $capture = StorePaymentObservation::query()->where('order_id', $order->id)->lockForUpdate()->findOrFail($capture->id);
            $attempt = StorePaymentAttempt::query()->where('order_id', $order->id)->lockForUpdate()->findOrFail($capture->attempt_id);
            if ($capture->processing_state === 'posted') {
                return $order;
            }
            if (StorePaymentObservation::query()->where('attempt_id', $attempt->id)->where('observation_version', '>', $capture->observation_version)->exists()) {
                $capture->update(['processing_state' => 'review', 'review_reason' => 'superseded_evidence']);

                return $order;
            }
            $issues = $capture->integrity_errors;
            if ($capture->review_reason === 'verification_changed') {
                $issues[] = 'verification_changed';
            }
            if ($order->payment_state === 'paid') {
                $issues[] = 'additional_capture';
            }
            if ($order->accepted_at === null) {
                $issues[] = 'quote_not_accepted';
            }
            $allocations = StoreStockAllocation::query()->where('order_id', $order->id)->orderBy('variant_id')->get();
            $variants = $this->locks->variants($allocations->pluck('variant_id')->all())->keyBy('id');
            if ($allocations->isEmpty() || $allocations->count() !== $order->lines()->count() || $allocations->contains(fn (StoreStockAllocation $a): bool => $a->state !== 'active' || $a->expires_at->lessThanOrEqualTo(now()) || $variants->get($a->variant_id)?->on_hand < $a->quantity)) {
                $issues[] = 'allocation_unavailable';
            }
            if ($issues !== []) {
                $txn = $capture->ledger_transaction_id === null ? $this->ledger->recordStoreCaptureReview($capture) : null;
                $capture->update(['processing_state' => 'review', 'review_reason' => $issues[0], 'ledger_transaction_id' => $capture->ledger_transaction_id ?? $txn?->id]);
                $attempt->update(['status' => 'review', 'review_reason' => $issues[0]]);
                if ($order->payment_state !== 'paid') {
                    $order->update(['state' => 'review', 'payment_state' => 'captured_review', 'ledger_state' => 'review', 'review_reason' => $issues[0]]);
                    app(PurchaseReservations::class)->release($order, 'payment_review');
                } else {
                    $order->update(['review_reason' => $issues[0]]);
                }

                return $order;
            }
            $transaction = $this->ledger->recordStoreSale($order, $attempt, ['observation_id' => $capture->id]);
            $capture->update(['ledger_transaction_id' => $transaction->id]);
            $order->update(['state' => 'confirmed', 'payment_state' => 'paid', 'ledger_state' => 'posted', 'paid_at' => $capture->paid_at, 'review_reason' => null]);
            app(MerchandiseInventory::class)->consumeOrder($order);
            $capture->update(['processing_state' => 'posted', 'review_reason' => null]);
            $attempt->update(['status' => 'succeeded', 'last_verified_at' => now()]);

            return $order;
        });
    }
}
