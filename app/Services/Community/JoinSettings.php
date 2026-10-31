<?php

declare(strict_types=1);

namespace App\Services\Community;

use App\Enum\TenantStatusEnum;
use App\Models\CommunitySettings;
use App\Models\MembershipType;
use App\Models\Tenant;
use App\Models\User;
use App\Scopes\TenantScope;
use Illuminate\Validation\ValidationException;

final class JoinSettings
{
    /** @return array{settings:CommunitySettings,type:MembershipType} */
    public function publicConfiguration(Tenant $tenant): array
    {
        $settings = CommunitySettings::for($tenant);
        $type = MembershipType::withoutGlobalScope(TenantScope::class)->where('tenant_id', $tenant->id)->where('is_active', true)->find($settings->public_membership_type_id);
        abort_unless($tenant->communityEnabled() && $tenant->status === TenantStatusEnum::ACTIVE && $settings->public_join_enabled
            && in_array($settings->join_mode, ['approval_required', 'immediate'], true) && $type !== null, 404);

        return ['settings' => $settings, 'type' => $type];
    }

    /** @param array<string,mixed> $input */
    public function update(Tenant $tenant, User $actor, array $input): CommunitySettings
    {
        app(MemberAccess::class)->authorizeStaff($tenant, $actor);
        abort_unless($tenant->communityEnabled(), 404);
        if (! in_array($input['join_mode'] ?? null, ['approval_required', 'immediate', 'invitation_only'], true)) {
            throw ValidationException::withMessages(['join_mode' => 'Choose a joining mode.']);
        }
        $typeId = $input['public_membership_type_id'] ?? null;
        if (($input['public_join_enabled'] ?? false) || $typeId !== null) {
            if (! MembershipType::withoutGlobalScope(TenantScope::class)->where('tenant_id', $tenant->id)->where('is_active', true)->whereKey($typeId)->exists()) {
                throw ValidationException::withMessages(['public_membership_type_id' => 'Choose an active membership type from this organisation.']);
            }
        }

        return app(MemberCap::class)->withLock($tenant, function () use ($tenant, $input): CommunitySettings {
            $settings = CommunitySettings::for($tenant);
            $settings->fill(array_intersect_key($input, array_flip(['join_mode', 'public_join_enabled', 'public_membership_type_id'])))->save();

            return $settings;
        });
    }
}
