<?php

declare(strict_types=1);

namespace App\Services\Marketplace;

use App\Models\StoreFulfilmentArrangement;
use App\Models\StoreFulfilmentEvent;
use App\Models\StoreOrder;
use App\Models\User;

final class StoreDeliveries
{
    public function dispatch(StoreOrder $order, User $actor, string $operationKey): StoreFulfilmentEvent
    {
        return $this->transition($order, $actor, $operationKey, 'dispatch', null, 'ready', 'dispatched');
    }

    public function attemptFailed(StoreOrder $order, User $actor, string $operationKey, string $reason): StoreFulfilmentEvent
    {
        return $this->transition($order, $actor, $operationKey, 'delivery_failed', $reason, 'dispatched', 'dispatched');
    }

    public function returned(StoreOrder $order, User $actor, string $operationKey, string $reason): StoreFulfilmentEvent
    {
        return $this->transition($order, $actor, $operationKey, 'returned', $reason, 'dispatched', 'ready');
    }

    private function transition(StoreOrder $order, User $actor, string $operationKey, string $kind, ?string $reason, string $from, string $to): StoreFulfilmentEvent
    {
        $reason = $reason === null ? null : mb_trim($reason);

        return $order->getConnection()->transaction(function () use ($order, $actor, $operationKey, $kind, $reason, $from, $to): StoreFulfilmentEvent {
            $order = app(CommerceInventoryLocks::class)->order($order);
            $service = app(StoreFulfilment::class);
            $service->authorize($order, $actor);
            if ($kind !== 'dispatch' && ($reason === null || $reason === '' || mb_strlen($reason) > 500)) {
                $service->refuse();
            }
            $arrangement = StoreFulfilmentArrangement::query()->where('order_id', $order->id)->first();
            if ($arrangement?->method !== 'delivery' || empty($arrangement->destination)) {
                $service->refuse();
            }
            $prior = $service->replay($order, $actor, $operationKey, $kind, $reason);
            if ($prior !== null) {
                return $prior;
            }
            if ($order->fulfilment_state !== $from || ($kind === 'dispatch' && ! $service->eligible($order))) {
                $service->refuse();
            }
            if ($kind !== 'dispatch') {
                app(StoreCollectionPasses::class)->revoke($order, $kind);
            }
            $event = $service->record($order, $actor, $operationKey, $kind, $reason);
            if ($from !== $to) {
                $order->update(['fulfilment_state' => $to]);
            }

            return $event;
        });
    }
}
