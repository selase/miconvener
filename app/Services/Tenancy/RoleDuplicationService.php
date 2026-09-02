<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

final readonly class RoleDuplicationService
{
    public function __construct(private EntitlementService $entitlementService) {}

    /**
     * Duplicate a system role for a specific tenant with filtered permissions.
     */
    public function duplicateRole(Role $source, Tenant $tenant, string $newName, array $permissions): Role
    {
        return DB::connection('landlord')->transaction(function () use ($source, $tenant, $newName, $permissions) {
            setPermissionsTeamId($tenant->id);

            $role = Role::create([
                'name' => $newName,
                'tenant_id' => $tenant->id,
                'guard_name' => 'web',
                'cloned_from_role_id' => $source->id,
            ]);

            $allowedPermissions = $this->entitlementService->getAllowedPermissionsForTenant($tenant->id);

            $safePermissions = array_values(array_intersect(
                $permissions,
                Permission::TENANT_SAFE,
                $allowedPermissions,
            ));

            if ($safePermissions !== []) {
                $role->syncPermissions($safePermissions);
            }

            return $role;
        });
    }
}
