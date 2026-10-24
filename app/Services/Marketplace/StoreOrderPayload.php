<?php

declare(strict_types=1);

namespace App\Services\Marketplace;

use App\Models\StoreOrder;
use App\Models\StoreOrderLine;

final class StoreOrderPayload
{
    /** Explicit buyer whitelist. A seller-borne fee is never a public receipt line.
     * @return array<string, mixed>
     */
    public function buyer(StoreOrder $order): array
    {
        $order->loadMissing(['lines', 'tenant.features', 'receipt.outbox', 'attempts', 'fulfilmentArrangement', 'collection']);
        $receipt = $order->receipt;
        $outbox = $receipt?->outbox;
        $terms = $order->terms_snapshot;
        $arrangement = $order->fulfilmentArrangement;
        $options = ($arrangement === null || $order->state === 'quoted') ? app(StoreFulfilmentOptions::class)->options(\App\Models\StoreVariant::query()->whereKey($order->lines->pluck('variant_id'))->get()->all()) : [];

        $unresolved = $order->attempts->contains(fn (\App\Models\StorePaymentAttempt $attempt): bool => in_array($attempt->status, ['initializing', 'unknown', 'pending'], true));
        $labels = ['awaiting' => ($order->state === 'cancelled' ? 'Quote released' : ($order->state === 'quoted' ? 'Quote ready' : 'Awaiting payment')), 'paid' => 'Paid', 'not_required' => 'Free order confirmed', 'captured_review' => 'Payment under review', 'refund_pending' => 'Refund requested', 'refunded' => $order->charged_pesewas === 0 ? 'Cancelled' : 'Refunded'];
        $data = ['fulfilment_method' => $arrangement?->method, 'fulfilment_options' => $options, 'fulfilment_terms' => $arrangement?->policy_snapshot, 'id' => $order->id, 'number' => mb_strtoupper(mb_substr(str_replace('-', '', $order->id), -12)), 'seller' => $order->seller_snapshot['name'] ?? 'Store', 'currency' => $order->currency,
            'total_pesewas' => $order->charged_pesewas, 'state' => $order->state, 'payment_state' => $order->payment_state,
            'payment_label' => $order->state === 'review' ? 'Payment under review' : ($labels[$order->payment_state] ?? 'Needs review'), 'fulfilment_state' => $order->fulfilment_state,
            'fulfilment_label' => match ($order->fulfilment_state) {
                'cancelled' => 'Cancelled', 'collected' => 'Collected', 'delivered' => 'Delivered', 'dispatched' => 'Out for delivery',
                'ready' => $arrangement?->method === 'delivery' ? 'Ready for dispatch' : 'Ready for collection',
                default => $order->state === 'confirmed' ? 'In preparation' : 'Not ready for handover',
            },
            'handed_over_at' => $order->collection?->handed_over_at->toIso8601String(),
            'expires_at' => $order->expires_at?->toIso8601String(), 'collection_terms' => $terms['collection_terms'] ?? '', 'refund_terms' => $terms['refund_terms'] ?? '', 'support_contact' => $terms['support_contact'] ?? '',
            'receipt_url' => $receipt === null ? null : route('store.orders.receipt', $order->id, false), 'email_state' => $outbox?->state, 'email_label' => match ($outbox?->state) {
                'sent' => 'Sent', 'queued', 'sending' => 'Delivery pending', 'retryable' => 'Delayed — retry scheduled', 'exhausted' => 'Delivery needs support', default => 'Not queued'
            },
            'cancel_allowed' => in_array($order->state, ['quoted', 'awaiting_payment'], true) && ! $order->attempts->contains(fn (\App\Models\StorePaymentAttempt $attempt): bool => ! in_array($attempt->status, ['failed', 'expired'], true)),
            'checkout_allowed' => $arrangement !== null && ! $unresolved && in_array($order->state, ['quoted', 'awaiting_payment'], true) && $order->expires_at?->isFuture() === true
                && app(CommerceCatalogRollout::class)->enabled($order->tenant) && $order->tenant->features->contains(fn ($feature): bool => $feature->feature_key === 'commerce_checkout' && (bool) $feature->enabled),
            'message' => $unresolved || $order->state === 'review' || $order->review_reason !== null ? 'Your payment needs review. Do not pay again. Contact the seller using the support details below.' : null,
            'lines' => $order->lines->map(fn (StoreOrderLine $line): array => ['id' => $line->id, 'title' => $line->title_snapshot, 'options' => $line->options_snapshot,
                'quantity' => $line->quantity, 'amount_pesewas' => $line->line_amount_pesewas])->all()];
        if ($order->fee_bearer === 'attendee') {
            $data['buyer_fee_pesewas'] = $order->platform_fee_pesewas;
        }

        return $data;
    }
}
