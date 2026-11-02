<?php

declare(strict_types=1);

namespace App\Services\Marketplace;

use App\Models\StoreOrder;

final class StoreCollectionPayload
{
    /** @return array<string, mixed> */
    public function summary(StoreOrder $order): array
    {
        $method = $order->fulfilmentArrangement?->method;

        return ['id' => $order->id, 'number' => mb_strtoupper(mb_substr($order->id, -8)),
            'buyer' => \App\Support\ContactMask::email($order->buyer_email),
            'method' => $method, 'fulfilment_state' => $order->fulfilment_state,
            'label' => match (true) {
                $order->state === 'cancelled' || $order->fulfilment_state === 'cancelled' => 'Cancelled',
                $order->payment_state === 'refunded' => 'Refunded',
                $order->payment_state === 'refund_pending' => 'Refund requested',
                $order->state === 'review' || $order->review_reason !== null => 'Needs review',
                $order->payment_state === 'awaiting' => 'Awaiting payment',
                $order->fulfilment_state === 'ready' => $method === 'delivery' ? 'Ready for dispatch' : 'Ready for collection',
                $order->fulfilment_state === 'dispatched' => 'Out for delivery', $order->fulfilment_state === 'collected' => 'Collected', $order->fulfilment_state === 'delivered' => 'Delivered',
                default => 'In preparation',
            }, 'item_count' => $order->lines->sum('quantity')];
    }

    /** @return array<string, mixed> */
    public function detail(StoreOrder $order): array
    {
        $order->loadMissing(['lines', 'fulfilmentArrangement', 'fulfilmentEvents', 'collection']);
        $service = app(StoreFulfilment::class);
        $enabled = $service->enabled($order);
        $eligible = $enabled && $service->eligible($order);
        $method = $order->fulfilmentArrangement?->method;
        $policy = $order->fulfilmentArrangement->policy_snapshot ?? [];
        $state = $order->fulfilment_state;

        return [...$this->summary($order),
            'lines' => $order->lines->map(fn ($line): array => ['id' => $line->id, 'title' => $line->title_snapshot, 'options' => $line->options_snapshot, 'quantity' => $line->quantity])->all(),
            'instructions' => implode("\n", array_filter($method === 'delivery' ? [$policy['delivery_timing'] ?? null, $policy['delivery_instructions'] ?? null, 'Delivery is included in the displayed price.'] : [$policy['collection_location'] ?? null, $policy['collection_hours'] ?? null, $policy['collection_instructions'] ?? null])),
            'destination' => $method === 'delivery' ? $order->fulfilmentArrangement->destination : null,
            'events' => $order->fulfilmentEvents->map(fn ($event): array => ['id' => $event->id, 'label' => match ($event->kind) {
                'ready' => 'Marked ready', 'prepare' => 'Returned to preparation', 'dispatch' => 'Dispatched', 'delivery_failed' => 'Delivery attempt unsuccessful', 'returned' => 'Goods returned to seller', default => 'Fulfilment updated',
            }, 'actor' => $event->actor_name, 'at' => $event->created_at->toIso8601String(), 'reason' => $event->reason])->all(),
            'collection' => $order->collection ? ['handed_over_at' => $order->collection->handed_over_at->toIso8601String(), 'actor' => $order->collection->actor_name, 'method' => $order->collection->method] : null,
            'ready_email_state' => app(StoreFulfilmentNotices::class)->status($order),
            'can_ready' => $eligible && $state === 'awaiting_preparation',
            'can_prepare' => $eligible && $state === 'ready',
            'can_dispatch' => $eligible && $method === 'delivery' && $state === 'ready',
            'can_return' => $enabled && $method === 'delivery' && $state === 'dispatched',
            'can_confirm' => $eligible && (($method === 'delivery' && $state === 'dispatched') || ($method === 'collection' && $state === 'ready')),
            'blocked_message' => ! $enabled ? 'Fulfilment is paused. History remains available.' : (! $eligible ? 'This order is not currently eligible for handover. Refresh its status or contact your finance administrator.' : null)];
    }
}
