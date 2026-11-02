<?php

declare(strict_types=1);

namespace App\Services\Marketplace;

use App\Jobs\DeliverStoreReadyNotice;
use App\Mail\StoreOrderReady;
use App\Models\StoreFulfilmentEvent;
use App\Models\StoreFulfilmentNotice;
use App\Models\StoreOrder;
use App\Support\WithoutTelescope;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Throwable;

final class StoreFulfilmentNotices
{
    public function queue(StoreFulfilmentEvent $event): StoreFulfilmentNotice
    {
        return $event->getConnection()->transaction(function () use ($event): StoreFulfilmentNotice {
            abort_unless($event->kind === 'ready', 409);
            $order = app(CommerceInventoryLocks::class)->order(StoreOrder::query()->findOrFail($event->order_id));
            $prior = StoreFulfilmentNotice::query()->where('event_id', $event->id)->first();
            if ($prior !== null) {
                return $prior;
            }
            $arrangement = $order->fulfilmentArrangement()->firstOrFail();
            $policy = $arrangement->policy_snapshot;
            $notice = StoreFulfilmentNotice::query()->create(['event_id' => $event->id, 'shop_id' => $order->shop_id, 'order_id' => $order->id, 'tenant_id' => $order->tenant_id,
                'destination' => $order->buyer_email, 'snapshot' => ['seller' => $order->seller_snapshot['name'] ?? 'Store', 'method' => $arrangement->method,
                    'instructions' => $arrangement->method === 'delivery' ? implode("\n", array_filter([$policy['delivery_timing'] ?? null, $policy['delivery_instructions'] ?? null, 'Delivery is included in the displayed price.'])) : implode("\n", array_filter([$policy['collection_location'] ?? null, $policy['collection_hours'] ?? null, $policy['collection_instructions'] ?? null])),
                    'support_contact' => $order->terms_snapshot['support_contact'] ?? '']]);
            $event->getConnection()->afterCommit(function () use ($notice): void {
                try {
                    DeliverStoreReadyNotice::dispatch($notice->id);
                } catch (Throwable) {
                    // The retained queued row is recovered by the scheduler.
                }
            });

            return $notice;
        });
    }

    public function status(StoreOrder $order): ?string
    {
        $event = $order->fulfilmentEvents()->where('kind', 'ready')->reorder()->orderByDesc('sequence')->first();

        return $event === null ? null : StoreFulfilmentNotice::query()->where('event_id', $event->id)->value('state');
    }

    public function deliver(string $id): void
    {
        $notice = StoreFulfilmentNotice::query()->findOrFail($id);
        $claimed = $notice->getConnection()->transaction(function () use ($notice): bool {
            $order = app(CommerceInventoryLocks::class)->order(StoreOrder::query()->findOrFail($notice->order_id));
            $current = StoreFulfilmentNotice::query()->lockForUpdate()->findOrFail($notice->id);
            if (! in_array($current->state, ['queued', 'retryable'], true) || $current->attempts >= 5 || $current->next_attempt_at?->isFuture()) {
                return false;
            }
            $latest = $order->fulfilmentEvents()->reorder()->orderByDesc('sequence')->first();
            if ($order->fulfilment_state !== 'ready' || $order->state !== 'confirmed' || $latest?->id !== $current->event_id) {
                $current->update(['state' => 'superseded', 'next_attempt_at' => null]);

                return false;
            }
            $service = app(StoreFulfilment::class);
            if (! $service->enabled($order) || ! $service->eligible($order)) {
                $current->update(['state' => 'retryable', 'next_attempt_at' => now()->addMinutes(5)]);

                return false;
            }
            $current->update(['state' => 'sending', 'attempts' => $current->attempts + 1, 'claimed_at' => now()]);

            return true;
        });
        if (! $claimed) {
            return;
        }
        try {
            WithoutTelescope::run(fn () => Mail::to($notice->destination)->send(new StoreOrderReady($notice)));
            $notice->update(['state' => 'sent', 'sent_at' => now(), 'next_attempt_at' => null]);
        } catch (Throwable) {
            $notice->refresh();
            $notice->update(['state' => $notice->attempts >= 5 ? 'exhausted' : 'retryable', 'next_attempt_at' => now()->addMinutes(5)]);
            throw new RuntimeException('Store readiness notice needs retry. The current order remains available in My MiConvener.');
        }
    }

    public function retryQueued(): void
    {
        StoreFulfilmentNotice::query()->where('state', 'sending')->where('claimed_at', '<=', now()->subMinutes(10))->update(['state' => 'retryable', 'next_attempt_at' => now()]);
        StoreFulfilmentNotice::query()->whereIn('state', ['queued', 'retryable'])->where('attempts', '>=', 5)->update(['state' => 'exhausted']);
        foreach (StoreFulfilmentNotice::query()->whereIn('state', ['queued', 'retryable'])->where('attempts', '<', 5)
            ->where(fn ($query) => $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))->oldest('updated_at')->limit(50)->get() as $notice) {
            try {
                DeliverStoreReadyNotice::dispatch($notice->id);
            } catch (Throwable) {
                $notice->touch();
            }
        }
    }
}
