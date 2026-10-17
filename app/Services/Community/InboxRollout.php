<?php

declare(strict_types=1);

namespace App\Services\Community;

use App\Enum\TenantStatusEnum;
use App\Models\Tenant;

final class InboxRollout
{
    public function enabled(Tenant $tenant): bool
    {
        return Tenant::query()->whereKey($tenant->id)->where('status', TenantStatusEnum::ACTIVE)->whereHas('features', fn ($q) => $q->where('feature_key', 'community_app')->where('enabled', true))->whereHas('features', fn ($q) => $q->where('feature_key', 'community_inbox')->where('enabled', true))->exists();
    }
}
