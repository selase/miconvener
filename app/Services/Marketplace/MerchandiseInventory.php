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
            $free = $order->charged_pesewas === 0 && $order->payment_state === 'not_required' && $order->ledger_state === 'not_applicable';
            $paid = false;
            if ($order->charged_pesewas > 0 && $order->payment_state === 'paid' && $order->ledger_state === 'posted') {
                $captures = \App\Models\StorePaymentObservation::query()->where('order_id', $order->id)->whereNotNull('ledger_transaction_id')->where('account_matches', true)->where('reference_matches', true)->get();
                $paid = $captures->contains(fn (\App\Models\StorePaymentObservation $capture): bool => $capture->integrity_errors === []
                    && $capture->amount_pesewas === $order->charged_pesewas && $capture->paid_at !== null && $capture->paid_at->lessThan($order->expires_at)
                    && \App\Models\LedgerTransaction::withoutGlobalScopes()->whereKey($capture->ledger_transaction_id)->where('tenant_id', $order->tenant_id)->where('reference', $capture->reference)->where('transaction_type', 'commerce_sale')->where('category', 'commerce')->whereNull('event_id')->exists());
            }
            if ((! $free && ! $paid) || $order->accepted_at === null) {
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

    public function restockRefund(\App\Models\StoreRefund $refund): void
    {
        $refund->getConnection()->transaction(function () use ($refund): void {
            $locks = app(CommerceInventoryLocks::class);
            $order = $locks->order($refund->order);
            $refund = \App\Models\StoreRefund::query()->where('order_id', $order->id)->lockForUpdate()->findOrFail($refund->id);
            if (! $refund->restock) {
                return;
            }
            $free = $refund->amount_pesewas === 0 && $order->charged_pesewas === 0 && $refund->provider_status === 'not_required';
            $paid = $refund->amount_pesewas > 0 && $refund->provider_processed_at !== null && \App\Models\LedgerTransaction::withoutGlobalScopes()
                ->whereKey($refund->ledger_transaction_id)->where('tenant_id', $order->tenant_id)->where('category', 'commerce')->where('transaction_type', 'commerce_refund')->where('reference', 'store-refund:'.$refund->operation_key)->exists();
            if ($refund->status !== 'confirmed' || (! $free && ! $paid) || in_array($order->fulfilment_state, ['collected', 'delivered', 'dispatched'], true)) {
                throw ValidationException::withMessages(['stock' => 'A confirmed refund of eligible uncollected goods is required.']);
            }
            $lines = $order->lines()->orderBy('variant_id')->get();
            $variants = $locks->variants($lines->pluck('variant_id')->all())->keyBy('id');
            foreach ($lines as $line) {
                if (StoreStockMovement::query()->where('variant_id', $line->variant_id)->where('source_kind', 'refund')->where('source_id', $refund->id)->exists()) {
                    continue;
                }
                $consumed = StoreStockMovement::query()->where('variant_id', $line->variant_id)->where('source_kind', 'order')->where('source_id', $order->id)->where('delta', -$line->quantity)->exists();
                $variant = $variants->get($line->variant_id);
                if (! $consumed || $variant === null || $variant->on_hand > 2147483647 - $line->quantity) {
                    throw ValidationException::withMessages(['stock' => 'The original stock movement needs review before restocking.']);
                }
                $balance = $variant->on_hand + $line->quantity;
                StoreVariant::query()->whereKey($variant->id)->update(['on_hand' => $balance]);
                StoreStockMovement::query()->create(['variant_id' => $variant->id, 'shop_id' => $variant->shop_id,
                    'idempotency_key' => $refund->id, 'delta' => $line->quantity, 'balance_after' => $balance, 'reason' => 'Uncollected order refund',
                    'actor_id' => null, 'actor_name' => 'Confirmed refund', 'source_kind' => 'refund', 'source_id' => $refund->id]);
            }
        });
    }
}
