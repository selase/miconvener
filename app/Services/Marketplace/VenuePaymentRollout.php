<?php

declare(strict_types=1);

namespace App\Services\Marketplace;

use App\Models\Tenant;

/**
 * Whether an organisation takes venue payments. Needs the explicit payments
 * feature row and approval-first requests; no plan turns it on. This switch
 * governs starting new checkouts only: money already taken is always recorded.
 */
final class VenuePaymentRollout
{
    public const FEATURE = 'marketplace_venue_payments';

    public function __construct(private readonly VenueRequestRollout $requests) {}

    public function enabled(Tenant $tenant): bool
    {
        return $this->requests->enabled($tenant)
            && $tenant->features()->where('feature_key', self::FEATURE)->where('enabled', true)->exists();
    }
}
