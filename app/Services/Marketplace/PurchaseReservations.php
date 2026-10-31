<?php

declare(strict_types=1);

namespace App\Services\Marketplace;

use App\Contracts\StoreReservationAdapter;
use App\Models\StoreOrder;
use App\Models\StoreStockAllocation;
use Illuminate\Validation\ValidationException;

final class PurchaseReservations implements StoreReservationAdapter
{
    public function __construct(private readonly CommerceInventoryLocks $locks) {}

    public function reserve(StoreOrder $order): void
    {
        $order->getConnection()->transaction(function () use ($order): void {
            $order = $this->locks->order($order);
            if ($order->sellerQuote()->exists() || $order->offering_type !== 'purchase' || $order->state !== 'quoted' || $order->expires_at->lessThanOrEqualTo(now())) {
                $this->refuse();
            }
            $lines = $order->lines()->orderBy('variant_id')->get();
            $variants = $this->locks->variants($lines->pluck('variant_id')->all())->keyBy('id');
            foreach ($lines as $line) {
                $prior = StoreStockAllocation::query()->where('line_id', $line->id)->first();
                if ($prior !== null) {
                    if ($prior->state !== 'active') {
                        $this->refuse();
                    }

                    continue;
                }
                $variant = $variants->get($line->variant_id);
                $held = (int) StoreStockAllocation::query()->where('variant_id', $line->variant_id)->where('state', 'active')->where('expires_at', '>', now())->sum('quantity');
                if ($variant === null || $variant->shop_id !== $order->shop_id || $variant->on_hand - $held < $line->quantity) {
                    $this->refuse();
                }
                StoreStockAllocation::query()->create(['order_id' => $order->id, 'line_id' => $line->id, 'shop_id' => $order->shop_id,
                    'variant_id' => $line->variant_id, 'quantity' => $line->quantity, 'expires_at' => $order->expires_at]);
            }
        });
    }

    public function release(StoreOrder $order, string $reason): void
    {
        if (! in_array($reason, ['expired', 'cancelled', 'replaced', 'payment_failed', 'payment_review'], true)) {
            $this->refuse();
        }
        $order->getConnection()->transaction(function () use ($order, $reason): void {
            $order = $this->locks->order($order);
            if (in_array($order->payment_state, ['paid', 'refund_pending', 'refunded'], true)) {
                $this->refuse();
            }
            $allocations = StoreStockAllocation::query()->where('order_id', $order->id)->where('state', 'active')->get();
            $this->locks->variants($allocations->pluck('variant_id')->all());
            foreach ($allocations as $allocation) {
                $allocation->update(['state' => 'released', 'release_reason' => $reason]);
            }
        });
    }

    public function consume(StoreOrder $order): void
    {
        app(MerchandiseInventory::class)->consumeOrder($order);
    }

    private function refuse(): never
    {
        throw ValidationException::withMessages(['stock' => 'The selected stock is unavailable or this reservation is no longer valid.']);
    }
}
