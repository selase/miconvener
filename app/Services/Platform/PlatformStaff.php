<?php

declare(strict_types=1);

namespace App\Services\Platform;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * MiConvener's own staff: people whose role belongs to the platform, not to an
 * organisation. Who gets a platform email, and where they land after signing
 * in, follows the permissions their role holds.
 */
final class PlatformStaff
{
    /**
     * Addresses of everyone with a platform-wide role that holds $permission.
     *
     * @return list<string>
     */
    public function emailsWithPermission(string $permission): array
    {
        // model_has_roles.model_id is a string column, so the ids are read
        // first and compared as integers, as SuperadminNotifiable does.
        $userIds = DB::connection('landlord')->table('model_has_roles')
            ->join('role_has_permissions', 'role_has_permissions.role_id', '=', 'model_has_roles.role_id')
            ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
            ->where('permissions.name', $permission)
            ->where('model_has_roles.model_type', User::class)
            ->whereNull('model_has_roles.tenant_id')
            ->pluck('model_has_roles.model_id')
            ->filter(fn (mixed $id): bool => is_numeric($id))
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->all();

        return User::query()
            ->whereIn('id', $userIds)
            ->where(fn ($query) => $query->whereNull('status')->orWhere('status', User::STATUS_ACTIVE))
            ->pluck('email')
            ->filter(fn (mixed $email): bool => is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL) !== false)
            ->map(fn (string $email): string => mb_strtolower(mb_trim($email)))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Where a platform-staff member who is not a superadmin lands after
     * signing in, by what they can do. Null when they have no home of their
     * own, so the usual dashboard rules apply.
     */
    public function landingRouteFor(?User $user): ?string
    {
        return match (true) {
            $user === null => null,
            $user->can('view platform finance') => 'admin.finance.overview',
            $user->can('view signups') => 'admin.signups.index',
            default => null,
        };
    }
}
