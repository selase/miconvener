<?php

declare(strict_types=1);

namespace App\Services\Marketplace;

use App\Jobs\DeliverStoreReceipt;
use App\Mail\StoreOrderReceipt;
use App\Models\StoreOrder;
use App\Models\StoreOutboxMessage;
use App\Models\StoreReceipt;
use App\Support\WithoutTelescope;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Throwable;

final class StoreReceipts
{
    /** Capture inside the source confirmation transaction; the outbox survives dispatch failure. */
    public function capture(StoreOrder $order): StoreReceipt
    {
        return $order->getConnection()->transaction(function () use ($order): StoreReceipt {
            $order = app(CommerceOrderLocks::class)->order($order);
            abort_unless($order->state === 'confirmed' && in_array($order->payment_state, ['paid', 'not_required'], true), 409);
            $existing = StoreReceipt::query()->where('order_id', $order->id)->first();
            if ($existing !== null) {
                return $existing;
            }
            $data = app(CommerceOfferingRegistry::class)->for($order)->receiptContext($order);
            $snapshot = array_intersect_key($data, array_flip(['number', 'seller', 'currency', 'total_pesewas', 'lines', 'buyer_fee_pesewas', 'collection_terms', 'refund_terms', 'support_contact', 'charges', 'production_terms', 'customisation']));
            $snapshot['issued_at'] = now()->toIso8601String();
            $snapshot['receipt_terms'] = $order->terms_snapshot['receipt_terms'] ?? '';
            $receipt = StoreReceipt::query()->create(['order_id' => $order->id, 'tenant_id' => $order->tenant_id, 'shop_id' => $order->shop_id,
                'number' => 'STORE-'.mb_strtoupper(str_replace('-', '', $order->id)), 'snapshot' => $snapshot]);
            $notice = StoreOutboxMessage::query()->create(['receipt_id' => $receipt->id, 'destination' => $order->buyer_email]);
            $order->getConnection()->afterCommit(function () use ($notice): void {
                try {
                    DeliverStoreReceipt::dispatch($notice->id);
                } catch (Throwable) { /* A durable queued row is recovered by the bounded scheduler sweep. */
                }
            });

            return $receipt;
        });
    }

    public function deliver(string $id): void
    {
        $notice = StoreOutboxMessage::query()->findOrFail($id);
        $claimed = $notice->getConnection()->transaction(function () use ($id): bool {
            $notice = StoreOutboxMessage::query()->lockForUpdate()->findOrFail($id);
            if (! in_array($notice->state, ['queued', 'retryable'], true) || $notice->attempts >= 5 || $notice->next_attempt_at?->isFuture()) {
                return false;
            }
            $notice->update(['state' => 'sending', 'attempts' => $notice->attempts + 1, 'claimed_at' => now()]);

            return true;
        });
        if (! $claimed) {
            return;
        }
        try {
            WithoutTelescope::run(fn () => Mail::to($notice->destination)->send(new StoreOrderReceipt(StoreReceipt::query()->findOrFail($notice->receipt_id))));
            $notice->update(['state' => 'sent', 'sent_at' => now(), 'next_attempt_at' => null]);
        } catch (Throwable) {
            $notice->refresh();
            $notice->update(['state' => $notice->attempts >= 5 ? 'exhausted' : 'retryable', 'next_attempt_at' => now()->addMinutes(5)]);
            throw new RuntimeException('Store receipt delivery needs retry. The receipt remains available in My MiConvener.');
        }
    }

    public function retryQueued(): void
    {
        StoreOutboxMessage::query()->where('state', 'sending')->where('claimed_at', '<=', now()->subMinutes(10))->update(['state' => 'retryable', 'next_attempt_at' => now()]);
        StoreOutboxMessage::query()->whereIn('state', ['queued', 'retryable'])->where('attempts', '>=', 5)->update(['state' => 'exhausted']);
        foreach (StoreOutboxMessage::query()->whereIn('state', ['queued', 'retryable'])->where('attempts', '<', 5)
            ->where(fn ($query) => $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))->orderBy('updated_at')->limit(50)->get() as $notice) {
            try {
                DeliverStoreReceipt::dispatch($notice->id);
            } catch (Throwable) {
                $notice->touch();
            }
        }
    }
}
