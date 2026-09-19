<?php

declare(strict_types=1);

namespace App\Services\Authorization;

use App\Models\Role;
use App\Models\User;

/**
 * Nobody can hand out more than they hold.
 *
 * Without this, anyone allowed to edit team members could give themselves a
 * stronger role, and anyone allowed to create roles could put permissions
 * they lack into one and then take it. Both were reproducible: a custom role
 * holding only "read user" and "update user" promoted itself to Org Admin.
 *
 * Organization owners and the platform Superadmin are not bounded: they
 * already hold everything a tenant role can contain.
 */
final class PermissionCeiling
{
    public function isUnbounded(User $actor): bool
    {
        return $actor->isGlobalSuperAdmin() || $actor->hasRole('Org Superadmin');
    }

    /**
     * The requested permissions the actor does not hold themselves.
     *
     * @param  iterable<int, string>  $permissions
     * @return list<string>
     */
    public function beyondReach(User $actor, iterable $permissions): array
    {
        if ($this->isUnbounded($actor)) {
            return [];
        }

        $held = $actor->getAllPermissions()->pluck('name')->all();

        return array_values(array_diff(collect($permissions)->map(fn (mixed $name): string => (string) $name)->all(), $held));
    }

    public function canGrantRole(User $actor, Role $role): bool
    {
        return $this->beyondReach($actor, $role->loadMissing('permissions')->permissions->pluck('name')) === [];
    }
}
