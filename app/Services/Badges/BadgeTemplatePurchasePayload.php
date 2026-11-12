<?php

declare(strict_types=1);

namespace App\Services\Badges;

use App\Models\BadgeTemplatePurchase;

final class BadgeTemplatePurchasePayload
{
    /** @return array<string, mixed> */
    public function forPurchase(BadgeTemplatePurchase $purchase): array
    {
        $purchase->loadMissing(['order.receipt.outbox', 'order.attempts', 'licence', 'event' => fn ($query) => $query->withTrashed()->with('tenant'), 'version']);

        if (! array_key_exists('uses_exists', $purchase->getAttributes())) {
            $purchase->loadExists('uses');
        }
        $current = $purchase->licence->current_purchase_id === $purchase->id;
        $licenceState = $current ? $purchase->licence->state : ($purchase->state === 'paid' ? 'superseded' : $purchase->state);

        return [
            'purchase_id' => $purchase->id,
            'state' => $purchase->state,
            'event' => ['id' => $purchase->event_id, 'name' => $purchase->terms_snapshot['event_name'] ?? $purchase->event->name, 'archived' => $purchase->event->trashed()],
            'event_badges_url' => $purchase->event->trashed() ? null : route('tenant.events.section', ['subdomain' => $purchase->event->tenant->slug, 'event' => $purchase->event_id, 'section' => 'badges']),
            'family_id' => $purchase->family_id,
            'version_id' => $purchase->version_id,
            'terms' => $purchase->terms_snapshot,
            'terms_hash' => $purchase->order->terms_hash,
            'expires_at' => $purchase->order->expires_at?->toIso8601String(),
            'payment_state' => $purchase->order->payment_state,
            'attempt_state' => $purchase->order->attempts->sortBy([['created_at', 'desc'], ['id', 'desc']])->first()?->status,
            'licence_state' => $licenceState,
            'used' => $purchase->uses_exists || ($current && $purchase->licence->first_used_at !== null),
            'receipt' => $purchase->order->receipt?->snapshot,
            'receipt_delivery' => $purchase->order->receipt?->outbox?->state,
        ];
    }
}
