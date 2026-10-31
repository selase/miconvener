<?php

declare(strict_types=1);

namespace App\Services\Community;

use App\Models\Tenant;

final class MessageEntitlements
{
    public function canSchedule(Tenant $tenant): bool
    {
        return $this->allowed($tenant, 'community_message_scheduling', ['starter', 'growth', 'enterprise']);
    }

    public function canMessageGroups(Tenant $tenant): bool
    {
        return $this->allowed($tenant, 'community_group_messaging', ['growth', 'enterprise']);
    }

    /** @param list<string> $plans */
    private function allowed(Tenant $tenant, string $key, array $plans): bool
    {
        $override = $tenant->features()->where('feature_key', $key)->first();

        return $override === null ? in_array($tenant->package?->slug, $plans, true) : (bool) $override->enabled;
    }
}
