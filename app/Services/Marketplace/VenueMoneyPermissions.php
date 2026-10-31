<?php

declare(strict_types=1);

namespace App\Services\Marketplace;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

/**
 * The venue-money permissions are separate from `manage venue` and from the
 * merchandise store-finance permissions: a user needs the specific permission,
 * granted inside the organisation that owns the money.
 */
final class VenueMoneyPermissions
{
    public const REFUNDS = 'manage venue refunds';

    public const RECONCILE = 'reconcile venue payments';

    public const SETTLEMENTS = 'release venue settlements';

    public function allows(User $actor, string $tenantId, string $permission): bool
    {
        $member = User::query()->whereKey($actor->id)->where('tenant_id', $tenantId)->exists() || $actor->tenants()->whereKey($tenantId)->exists();
        if (! $member) {
            return false;
        }
        $team = getPermissionsTeamId();
        try {
            setPermissionsTeamId($tenantId);

            return Gate::forUser($actor->fresh())->allows($permission);
        } finally {
            setPermissionsTeamId($team);
        }
    }

    /**
     * @throws AuthorizationException
     */
    public function authorize(User $actor, string $tenantId, string $permission): void
    {
        if (! $this->allows($actor, $tenantId, $permission)) {
            throw new AuthorizationException;
        }
    }
}
