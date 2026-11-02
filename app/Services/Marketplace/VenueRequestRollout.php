<?php

declare(strict_types=1);

namespace App\Services\Marketplace;

use App\Enum\TenantStatusEnum;
use App\Models\Tenant;

/**
 * Whether an organisation takes approval-first public venue requests. Only an
 * explicit, enabled feature row opts in: a plan never turns requests on.
 */
final class VenueRequestRollout
{
    public const FEATURE = 'marketplace_venue_requests';

    public function enabled(Tenant $tenant): bool
    {
        return $this->organisations()->whereKey($tenant->id)->exists();
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<Tenant>
     */
    public function organisations(): \Illuminate\Database\Eloquent\Builder
    {
        return Tenant::query()
            ->where('status', TenantStatusEnum::ACTIVE)
            ->whereHas('features', fn ($features) => $features->where('feature_key', self::FEATURE)->where('enabled', true))
            ->whereHas('shop', fn ($shops) => $shops->where('is_active', true));
    }
}
