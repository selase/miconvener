<?php

declare(strict_types=1);

namespace App\Services\Marketplace;

use App\Models\StoreFulfilmentArrangement;
use App\Models\StoreFulfilmentEvent;
use App\Models\StoreOrder;
use App\Models\StorePaymentObservation;
use App\Models\StoreRefund;
use App\Models\StoreStockAllocation;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;

final class StoreFulfilment
{
    public function eligible(StoreOrder $order): bool
    {
        if ($order->state !== 'confirmed' || $order->review_reason !== null
            || ! (($order->payment_state === 'paid' && $order->ledger_state === 'posted') || ($order->payment_state === 'not_required' && $order->charged_pesewas === 0 && $order->ledger_state === 'not_applicable'))
            || ! StoreFulfilmentArrangement::query()->where('order_id', $order->id)->exists()
            || $order->attempts()->whereNotIn('status', ['succeeded', 'failed', 'expired'])->exists()
            || StorePaymentObservation::query()->where('order_id', $order->id)->whereIn('processing_state', ['pending', 'review'])->exists()
            || StoreRefund::query()->where('order_id', $order->id)->where('restock', true)->where('status', '<>', 'confirmed')->exists()) {
            return false;
        }
        $lines = $order->lines()->get()->keyBy('id');
        $allocations = StoreStockAllocation::query()->where('order_id', $order->id)->get();

        return $lines->isNotEmpty() && $lines->count() === $allocations->count()
            && $allocations->every(fn (StoreStockAllocation $a): bool => $a->state === 'consumed' && $lines->has($a->line_id) && $lines->get($a->line_id)->quantity === $a->quantity);
    }

    public function ready(StoreOrder $order, User $actor, string $operationKey): StoreFulfilmentEvent
    {
        return $order->getConnection()->transaction(function () use ($order, $actor, $operationKey): StoreFulfilmentEvent {
            $order = app(CommerceInventoryLocks::class)->order($order);
            $this->authorize($order, $actor);
            $prior = $this->replay($order, $actor, $operationKey, 'ready', null);
            if ($prior !== null) {
                return $prior;
            }
            if ($order->fulfilment_state !== 'awaiting_preparation' || ! $this->eligible($order)) {
                $this->refuse();
            }
            $event = $this->record($order, $actor, $operationKey, 'ready');
            $order->update(['fulfilment_state' => 'ready']);

            return $event;
        });
    }

    public function authorize(StoreOrder $order, User $actor, bool $requireEnabled = true): void
    {
        if (! app(StoreOrderAccess::class)->staff($order, $order->tenant, $actor, 'manage store collection')) {
            throw new AuthorizationException;
        }
        if ($requireEnabled && ! $this->enabled($order)) {
            $this->refuse();
        }
    }

    public function enabled(StoreOrder $order): bool
    {
        return $order->tenant()->where('status', 'active')->whereHas('features', fn ($query) => $query->where('feature_key', 'commerce_collection')->where('enabled', true))->exists();
    }

    public function replay(StoreOrder $order, User $actor, string $operationKey, string $kind, ?string $reason): ?StoreFulfilmentEvent
    {
        if (! Str::isUuid($operationKey)) {
            $this->refuse();
        }
        $prior = StoreFulfilmentEvent::query()->where('order_id', $order->id)->where('operation_key', $operationKey)->first();
        if ($prior !== null && ($prior->kind !== $kind || (string) $prior->actor_id !== (string) $actor->id || $prior->reason !== $reason)) {
            $this->refuse();
        }

        return $prior;
    }

    /** Caller must hold canonical tenant/order locks. */
    public function record(StoreOrder $order, User $actor, string $operationKey, string $kind, ?string $reason = null): StoreFulfilmentEvent
    {
        if ($order->getConnection()->transactionLevel() < 1) {
            throw new LogicException('Fulfilment requires a transaction.');
        }

        return StoreFulfilmentEvent::query()->create(['order_id' => $order->id, 'shop_id' => $order->shop_id, 'tenant_id' => $order->tenant_id,
            'kind' => $kind, 'operation_key' => $operationKey, 'actor_id' => $actor->id, 'actor_name' => $actor->name, 'reason' => $reason,
            'lines_snapshot' => $order->lines()->get()->map(fn ($line): array => ['id' => $line->id, 'variant_id' => $line->variant_id, 'quantity' => $line->quantity, 'title' => $line->title_snapshot, 'options' => $line->options_snapshot])->all(),
            'terms_hash' => $order->terms_hash]);
    }

    public function refuse(): never
    {
        throw ValidationException::withMessages(['fulfilment' => 'This order cannot be handed over in its current state. Refresh its status or contact support.']);
    }
}
