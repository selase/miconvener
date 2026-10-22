<?php

declare(strict_types=1);

namespace App\Services\Marketplace;

use App\Enum\TenantStatusEnum;
use App\Models\Tenant;

final class CommerceCatalogRollout
{
    public function baseEnabled(Tenant $tenant): bool
    {
        return Tenant::query()->whereKey($tenant->id)->where('status', TenantStatusEnum::ACTIVE)
            ->whereHas('features', fn ($feature) => $feature->where('feature_key', 'commerce_catalog')->where('enabled', true))->exists();
    }

    public function enabled(Tenant $tenant): bool
    {
        return $this->baseEnabled($tenant) && $tenant->shop()->where('is_active', true)->exists();
    }
}
