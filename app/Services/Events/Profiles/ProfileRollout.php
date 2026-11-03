<?php

declare(strict_types=1);

namespace App\Services\Events\Profiles;

use App\Models\Event;
use App\Models\Tenant;
use App\Models\TenantFeature;

final class ProfileRollout
{
    public const string FEATURE = 'public_attendee_profiles';

    public function generation(Tenant|string $tenant): ?string
    {
        $id = $tenant instanceof Tenant ? $tenant->id : $tenant;
        if (! Tenant::query()->whereKey($id)->where('status', \App\Enum\TenantStatusEnum::ACTIVE)->exists()) {
            return null;
        }
        $row = TenantFeature::query()->where('tenant_id', $tenant instanceof Tenant ? $tenant->id : $tenant)
            ->where('feature_key', self::FEATURE)->where('enabled', true)->first();
        $generation = $row?->meta['profile_generation'] ?? null;

        return is_string($generation) ? $generation : null;
    }

    public function allows(Event $event): bool
    {
        $tenantId = Event::withoutGlobalScopes()->whereKey($event->id)->whereNull('deleted_at')->where('public_attendee_profiles', true)->value('tenant_id');

        return is_string($tenantId) && $this->generation($tenantId) !== null;
    }
}
