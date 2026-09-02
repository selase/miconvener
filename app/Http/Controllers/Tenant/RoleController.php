<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\DuplicateRoleRequest;
use App\Models\Permission;
use App\Models\Role;
use App\Services\Tenancy\RoleDuplicationService;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

final class RoleController extends Controller
{
    public function index(string $subdomain): View|JsonResponse
    {
        $this->authorize('read role');
        $tenant = $this->getTenant();

        $allRoles = Role::with('permissions')
            ->where(function ($query) use ($tenant): void {
                $query->where('tenant_id', $tenant->id)
                    ->orWhereNull('tenant_id');
            })->get();

        if (request()->wantsJson() || request()->ajax()) {
            return response()->json($allRoles);
        }

        $systemRoles = $allRoles->filter(fn (Role $role): bool => $role->tenant_id === null);
        $customRoles = $allRoles->filter(fn (Role $role): bool => $role->tenant_id !== null);

        // Load user counts for custom roles (needed for delete guard UI)
        $customRoles->each(function (Role $role): void {
            $role->setAttribute('users_count', $role->assignedUsersCount());
        });

        return view('tenant.roles.index', ['systemRoles' => $systemRoles, 'customRoles' => $customRoles]);
    }

    public function create(string $subdomain): View
    {
        $this->authorize('create role');
        $tenant = $this->getTenant();
        $allowedPermissionNames = app(\App\Services\Tenancy\EntitlementService::class)->getAllowedPermissionsForTenant($tenant->id);
        $permissions = Permission::whereIn('name', $allowedPermissionNames)->get();

        return view('tenant.roles.create', ['permissions' => $permissions]);
    }

    public function show(string $subdomain, string $id): View|JsonResponse
    {
        $this->authorize('read role');
        $tenant = $this->getTenant();

        $role = Role::where(function ($query) use ($tenant): void {
            $query->where('tenant_id', $tenant->id)
                ->orWhereNull('tenant_id');
        })->where('id', $id)->firstOrFail();

        if (request()->wantsJson() || request()->ajax()) {
            return response()->json($role->load('permissions'));
        }

        return view('tenant.roles.show', ['role' => $role]);
    }

    public function edit(string $subdomain, string $id): View
    {
        $this->authorize('update role');
        $tenant = $this->getTenant();
        $role = Role::where('tenant_id', $tenant->id)
            ->where('id', $id)
            ->with('permissions')
            ->firstOrFail();

        $allowedPermissionNames = app(\App\Services\Tenancy\EntitlementService::class)->getAllowedPermissionsForTenant($tenant->id);
        $permissions = Permission::whereIn('name', $allowedPermissionNames)->get();

        // Load source role permissions for comparison if this is a cloned role
        $sourcePermissions = [];
        if ($role->cloned_from_role_id) {
            $sourceRole = $role->clonedFromRole;
            $sourcePermissions = $sourceRole?->permissions->pluck('name')->toArray() ?? [];
        }

        return view('tenant.roles.edit', ['role' => $role, 'permissions' => $permissions, 'sourcePermissions' => $sourcePermissions]);
    }

    public function store(Request $request, string $subdomain): RedirectResponse|JsonResponse
    {
        $this->authorize('create role');
        $tenant = $this->getTenant();

        $allowedPermissionNames = app(\App\Services\Tenancy\EntitlementService::class)->getAllowedPermissionsForTenant($tenant->id);

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                Rule::unique('roles')->where(fn($query) => $query->where('tenant_id', $tenant->id)),
                Rule::notIn(Role::SYSTEM_ROLES),
            ],
            'permissions' => 'array',
            'permissions.*' => [
                'string',
                Rule::in($allowedPermissionNames),
            ],
        ]);

        return DB::transaction(function () use ($validated, $tenant, $request) {
            // Ensure we set the team ID for Spatie
            setPermissionsTeamId($tenant->id);

            $role = Role::create([
                'name' => $validated['name'],
                'tenant_id' => $tenant->id,
                'guard_name' => 'web',
            ]);

            if (isset($validated['permissions'])) {
                $role->syncPermissions($validated['permissions']);
            }

            if ($request->wantsJson()) {
                return response()->json($role->load('permissions'));
            }

            return redirect()->route('tenant.roles.index', ['subdomain' => $tenant->slug])->with('success', 'Role created successfully.');
        });
    }

    public function update(Request $request, string $subdomain, string $id): RedirectResponse|JsonResponse
    {
        $this->authorize('update role');
        $tenant = $this->getTenant();

        $role = Role::where('tenant_id', $tenant->id)
            ->where('id', $id)
            ->firstOrFail();

        if ($role->isSystemRole()) {
            return response()->json(['message' => 'Cannot modify system roles.'], 403);
        }

        $allowedPermissionNames = app(\App\Services\Tenancy\EntitlementService::class)->getAllowedPermissionsForTenant($tenant->id);

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                Rule::unique('roles')->ignore($role->id)->where(fn($query) => $query->where('tenant_id', $tenant->id)),
                Rule::notIn(Role::SYSTEM_ROLES),
            ],
            'permissions' => 'array',
            'permissions.*' => [
                'string',
                Rule::in($allowedPermissionNames),
            ],
        ]);

        return DB::transaction(function () use ($role, $validated, $tenant, $request) {
            setPermissionsTeamId($tenant->id);

            $role->update([
                'name' => $validated['name'],
            ]);

            if (isset($validated['permissions'])) {
                $role->syncPermissions($validated['permissions']);
            }

            if ($request->wantsJson()) {
                return response()->json($role->load('permissions'));
            }

            return redirect()->route('tenant.roles.index', ['subdomain' => $tenant->slug])->with('success', 'Role updated successfully.');
        });
    }

    public function duplicateForm(string $subdomain, string $role): View
    {
        $this->authorize('create role');
        $tenant = $this->getTenant();

        $sourceRole = Role::whereNull('tenant_id')
            ->where('id', $role)
            ->with('permissions')
            ->firstOrFail();

        $allowedPermissionNames = app(\App\Services\Tenancy\EntitlementService::class)->getAllowedPermissionsForTenant($tenant->id);
        $permissions = Permission::whereIn('name', $allowedPermissionNames)->get();

        // Pre-select permissions that the source role has (filtered to TENANT_SAFE)
        $sourcePermissionNames = $sourceRole->permissions
            ->pluck('name')
            ->intersect(Permission::TENANT_SAFE)
            ->intersect($allowedPermissionNames)
            ->toArray();

        return view('tenant.roles.duplicate', ['sourceRole' => $sourceRole, 'permissions' => $permissions, 'sourcePermissionNames' => $sourcePermissionNames]);
    }

    public function duplicate(DuplicateRoleRequest $request, string $subdomain): RedirectResponse|JsonResponse
    {
        $tenant = $this->getTenant();
        $validated = $request->validated();

        $source = Role::where('id', $validated['source_role_id'])
            ->whereNull('tenant_id')
            ->firstOrFail();

        $role = app(RoleDuplicationService::class)
            ->duplicateRole($source, $tenant, $validated['name'], $validated['permissions']);

        if ($request->wantsJson()) {
            return response()->json($role->load('permissions'));
        }

        return redirect()
            ->route('tenant.roles.index', ['subdomain' => $tenant->slug])
            ->with('success', "Role '{$role->name}' created. Assign users from User Management.");
    }

    public function destroy(string $subdomain, string $id): JsonResponse|RedirectResponse
    {
        $this->authorize('delete role');
        $tenant = $this->getTenant();

        $role = Role::where(function ($q) use ($tenant): void {
            $q->where('tenant_id', $tenant->id)
                ->orWhereNull('tenant_id');
        })
            ->where('id', $id)
            ->firstOrFail();

        if ($role->isSystemRole()) {
            return response()->json(['message' => 'Cannot delete system roles.'], 403);
        }

        $assignedCount = $role->assignedUsersCount();
        if ($assignedCount > 0) {
            $message = "{$assignedCount} user(s) are assigned to this role. Reassign them before deleting.";
            if (request()->wantsJson()) {
                return response()->json(['message' => $message], 422);
            }

            return redirect()->back()->with('error', $message);
        }

        $role->delete();

        if (request()->wantsJson()) {
            return response()->json(['message' => 'Role deleted successfully.']);
        }

        return redirect()->route('tenant.roles.index', ['subdomain' => $tenant->slug])->with('success', 'Role deleted successfully.');
    }

    protected function getTenant()
    {
        $tenant = app(TenantContext::class)->getTenant();
        if (! $tenant) {
            abort(403, 'Tenant context not resolved.');
        }

        return $tenant;
    }
}
