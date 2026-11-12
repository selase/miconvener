<?php

declare(strict_types=1);

namespace App\Services\Badges;

use App\Models\BadgeTemplateLicence;
use App\Models\BadgeTemplateLicenceEvent;
use App\Models\BadgeTemplatePurchase;
use App\Models\StorePaymentObservation;
use LogicException;

final class BadgeTemplateLicences
{
    public function grant(BadgeTemplatePurchase $purchase, StorePaymentObservation $capture): BadgeTemplateLicence
    {
        if ($purchase->getConnection()->transactionLevel() === 0) {
            throw new LogicException('Licence issuance must share the payment transaction.');
        }
        $purchase = BadgeTemplatePurchase::query()->whereKey($purchase->id)->firstOrFail();
        $capture = StorePaymentObservation::query()->whereKey($capture->id)->firstOrFail();
        $licence = BadgeTemplateLicence::query()->whereKey($purchase->licence_id)->lockForUpdate()->firstOrFail();
        if ($capture->order_id !== $purchase->order_id || $capture->processing_state !== 'posted' || $capture->ledger_transaction_id === null
            || $purchase->order->payment_state !== 'paid' || $purchase->event === null
            || $purchase->event->tenant_id !== $purchase->buyer_tenant_id) {
            throw new LogicException('A posted payment for this event purchase is required.');
        }
        if ($licence->state === 'active' && $licence->current_purchase_id === $purchase->id) {
            return $licence;
        }
        if (! in_array($licence->state, ['pending', 'refunded', 'revoked'], true) || $purchase->state !== 'pending') {
            throw new LogicException('The licence slot cannot receive this purchase.');
        }
        $purchase->update(['state' => 'paid']);
        $licence->update(['state' => 'active', 'current_purchase_id' => $purchase->id, 'first_used_at' => null]);
        BadgeTemplateLicenceEvent::query()->create(['licence_id' => $licence->id, 'purchase_id' => $purchase->id,
            'action' => 'issued', 'operation_key' => $capture->id,
            'evidence' => ['observation_id' => $capture->id, 'ledger_transaction_id' => $capture->ledger_transaction_id]]);

        return $licence;
    }
}
