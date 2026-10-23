<?php

declare(strict_types=1);

namespace App\Services\Marketplace;

use App\Models\StoreOrder;
use App\Models\StoreStockAllocation;
use App\Models\StoreStockMovement;
use App\Models\StoreVariant;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class MerchandiseInventory
{
    public function adjust(StoreVariant $variant, int $delta, string $reason, string $idempotencyKey, User $actor): StoreStockMovement
    {
        $reason = mb_trim($reason);
        if ($delta === 0 || $delta < -2147483647 || $delta > 2147483647 || $reason === '' || mb_strlen($reason) > 500 || ! Str::isUuid($idempotencyKey)) {
            throw ValidationException::withMessages(['adjustment' => 'Provide a non-zero quantity, a reason and a valid adjustment key.']);
        }

        return $variant->getConnection()->transaction(function () use ($variant, $delta, $reason, $idempotencyKey, $actor): StoreStockMovement {
            $locks = app(CommerceInventoryLocks::class);
            $current = StoreVariant::query()->with('shop')->findOrFail($variant->id);
            $locks->tenant($current->shop->tenant_id);
            $locks->variants([$variant->id]);
            $locked = StoreVariant::query()->with('shop')->lockForUpdate()->findOrFail($variant->id);
            if ($locked->shop === null || (! User::query()->whereKey($actor->id)->where('tenant_id', $locked->shop->tenant_id)->exists() && ! $actor->tenants()->whereKey($locked->shop->tenant_id)->exists())) {
                throw new AuthorizationException;
            }
            $team = getPermissionsTeamId();
            try {
                setPermissionsTeamId($locked->shop->tenant_id);
                $recordedActor = $actor->fresh();
                Gate::forUser($recordedActor)->authorize('manage store catalog');
            } finally {
                setPermissionsTeamId($team);
            }
            $prior = $locked->movements()->where('idempotency_key', $idempotencyKey)->first();
            if ($prior !== null) {
                if ($prior->delta !== $delta || $prior->reason !== $reason || $prior->actor_id !== $actor->id) {
                    throw ValidationException::withMessages(['idempotency_key' => 'This adjustment key was already used for a different correction.']);
                }

                return $prior;
            }
            $balance = $locked->on_hand + $delta;
            $held = (int) StoreStockAllocation::query()->where('variant_id', $locked->id)->where('state', 'active')->where('expires_at', '>', now())->sum('quantity');
            if ($balance < $held || $balance > 2147483647) {
                throw ValidationException::withMessages(['delta' => 'The correction would put stock outside its allowed range.']);
            }
            StoreVariant::query()->whereKey($locked->id)->update(['on_hand' => $balance]);

            return $locked->movements()->create([
                'shop_id' => $locked->shop_id, 'delta' => $delta, 'balance_after' => $balance,
                'reason' => $reason, 'actor_id' => $actor->id, 'actor_name' => $recordedActor->name, 'idempotency_key' => $idempotencyKey,
            ]);
        });
    }

    public function consumeOrder(StoreOrder $order): void
    {
        $order->getConnection()->transaction(function () use ($order): void {
            $locks = app(CommerceInventoryLocks::class);
            $order = $locks->order($order);
            if ($order->charged_pesewas !== 0 || $order->payment_state !== 'not_required' || $order->ledger_state !== 'not_applicable' || $order->accepted_at === null) {
                throw ValidationException::withMessages(['stock' => 'Stock requires an accepted free order or verified, posted payment.']);
            }
            $allocations = StoreStockAllocation::query()->where('order_id', $order->id)->orderBy('variant_id')->get();
            $variants = $locks->variants($allocations->pluck('variant_id')->all())->keyBy('id');
            if ($allocations->count() !== $order->lines()->count() || $allocations->isEmpty()) {
                throw ValidationException::withMessages(['stock' => 'The order allocation is incomplete.']);
            }
            foreach ($allocations as $allocation) {
                if ($allocation->state === 'consumed') {
                    continue;
                }
                $variant = $variants->get($allocation->variant_id);
                if ($allocation->state !== 'active' || $allocation->expires_at->lessThanOrEqualTo(now()) || $variant === null || $variant->on_hand < $allocation->quantity) {
                    throw ValidationException::withMessages(['stock' => 'The order reservation expired or needs review.']);
                }
                $balance = $variant->on_hand - $allocation->quantity;
                StoreVariant::query()->whereKey($variant->id)->update(['on_hand' => $balance]);
                StoreStockMovement::query()->create(['variant_id' => $variant->id, 'shop_id' => $variant->shop_id,
                    'idempotency_key' => $allocation->line_id, 'delta' => -$allocation->quantity, 'balance_after' => $balance,
                    'reason' => 'Order purchase', 'actor_id' => null, 'actor_name' => 'Verified order', 'source_kind' => 'order', 'source_id' => $order->id]);
                $allocation->update(['state' => 'consumed']);
            }
        });
    }
}
