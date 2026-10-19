<?php

declare(strict_types=1);

namespace App\Services\Community;

use App\Enum\TenantStatusEnum;
use App\Models\CommunityLibraryItem;
use App\Models\Tenant;

final class CommunityLibraryRollout
{
    public function baseEnabled(Tenant $tenant): bool
    {
        return Tenant::query()->whereKey($tenant->id)->where('status', TenantStatusEnum::ACTIVE)
            ->whereHas('features', fn ($q) => $q->where('feature_key', 'community')->where('enabled', true))
            ->whereHas('features', fn ($q) => $q->where('feature_key', 'community_app')->where('enabled', true))->exists();
    }

    public function available(Tenant $tenant): bool
    {
        return $this->baseEnabled($tenant) && ($tenant->features()->where('feature_key', 'community_library')->where('enabled', true)->exists()
            || CommunityLibraryItem::withoutGlobalScopes()->where('tenant_id', $tenant->id)->exists());
    }

    public function canPublish(Tenant $tenant): bool
    {
        $feature = $tenant->features()->where('feature_key', 'community_library_access')->first();
        $entitled = $feature !== null ? $feature->enabled : in_array($tenant->package?->slug, ['starter', 'growth', 'enterprise'], true);

        return $this->baseEnabled($tenant) && $entitled && $tenant->features()->where('feature_key', 'community_library')->where('enabled', true)->exists();
    }
}
