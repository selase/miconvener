<?php

declare(strict_types=1);

namespace App\Services\Marketplace;

use App\Contracts\CommerceOffering;
use App\Models\StoreOrder;
use LogicException;

/**
 * Resolves the offering that owns an order from its persisted type, never from
 * a loaded copy. An unknown type, an unsaved order or a reservation without its
 * venue charge fails closed: nothing falls back to the purchase rules.
 */
final class CommerceOfferingRegistry
{
    public function __construct(private readonly PurchaseOffering $purchase, private readonly VenueOffering $venue) {}

    public function for(StoreOrder $order): CommerceOffering
    {
        $type = $order->exists ? StoreOrder::query()->whereKey($order->id)->value('offering_type') : null;
        if (! is_string($type)) {
            throw new LogicException('The order type could not be resolved.');
        }
        $offering = $this->forType($type);
        if ($offering instanceof VenueOffering && ! $this->venue->hasCharge($order)) {
            throw new LogicException('A reservation order needs its venue charge.');
        }

        return $offering;
    }

    public function forType(string $type): CommerceOffering
    {
        return match ($type) {
            'purchase' => $this->purchase,
            'reservation' => $this->venue,
            default => throw new LogicException("Unsupported order type [{$type}]."),
        };
    }
}
