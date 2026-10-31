<?php

declare(strict_types=1);

namespace App\Services\Giving;

use App\Enum\TenantStatusEnum;
use App\Models\Tenant;

final class GivingRollout
{
    public function enabled(Tenant $tenant): bool
    {
        return Tenant::query()->whereKey($tenant->id)->where('status', TenantStatusEnum::ACTIVE)
            ->whereHas('features', fn ($q) => $q->where('feature_key', 'community')->where('enabled', true))
            ->whereHas('features', fn ($q) => $q->where('feature_key', 'community_giving')->where('enabled', true))->exists();
    }

    public function onlineEnabled(Tenant $tenant): bool
    {
        return $this->enabled($tenant) && ($tenant->features()->where('feature_key', 'community_giving')->first()?->meta['online_enabled'] ?? false) === true;
    }
}
