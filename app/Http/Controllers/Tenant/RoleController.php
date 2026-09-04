<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\DuplicateRoleRequest;
use App\Models\Permission;
use App\Models\Role;
use App\Services\Tenancy\EntitlementService;
use App\Services\Tenancy\RoleDuplicationService;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class RoleController extends Controller
{
    public function index(string $subdomain): Response
    {
        $this->authorize('read role');
        $tenant = $this->getTenant();

        $allRoles = Role::with('permissions')
            ->where(function ($query) use ($tenant): void {
                $query->where('tenant_id', $tenant->id)
                    ->orWhereNull('tenant_id');
            })->get();

        $toPayload = fn (Role $role): array => [
            'id' => $role->id,
            'name' => $role->name,
            'permissions' => $role->permissions->pluck('name')->values()->all(),
            'is_system' => $role->tenant_id === null,
        ];

        $systemRoles = $allRoles->filter(fn (Role $role): bool => $role->tenant_id === null)->map($toPayload)->values();
        $customRoles = $allRoles->filter(fn (Role $role): bool => $role->tenant_id !== null)
            ->map(fn (Role $role): array => [...$toPayload($role), 'users_count' => $role->assignedUsersCount()])
            ->values();

        return Inertia::render('Tenant/Roles/Index', [
            'systemRoles' => $systemRoles,
            'customRoles' => $customRoles,
            'permissions' => $this->allowedPermissionNames(),
        ]);
    }

    public function store(Request $request, string $subdomain): RedirectResponse|JsonResponse
    {
        $this->authorize('create role');
        $tenant = $this->getTenant();

        $allowedPermissionNames = app(EntitlementService::class)->getAllowedPermissionsForTenant($tenant->id);

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                Rule::unique('roles')->where(fn ($query) => $query->where('tenant_id', $tenant->id)),
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

        $allowedPermissionNames = app(EntitlementService::class)->getAllowedPermissionsForTenant($tenant->id);

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                Rule::unique('roles')->ignore($role->id)->where(fn ($query) => $query->where('tenant_id', $tenant->id)),
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

    public function duplicateForm(string $subdomain, string $role): JsonResponse
    {
        $this->authorize('create role');
        $tenant = $this->getTenant();

        $sourceRole = Role::whereNull('tenant_id')
            ->where('id', $role)
            ->with('permissions')
            ->firstOrFail();

        $allowedPermissionNames = app(EntitlementService::class)->getAllowedPermissionsForTenant($tenant->id);

        // Pre-select permissions that the source role has (filtered to TENANT_SAFE)
        $sourcePermissionNames = $sourceRole->permissions
            ->pluck('name')
            ->intersect(Permission::TENANT_SAFE)
            ->intersect($allowedPermissionNames)
            ->values()
            ->all();

        return response()->json([
            'sourceRole' => [
                'id' => $sourceRole->id,
                'name' => $sourceRole->name,
            ],
            'permissions' => Permission::whereIn('name', $allowedPermissionNames)->pluck('name')->values()->all(),
            'sourcePermissionNames' => $sourcePermissionNames,
        ]);
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

    /**
     * @return array<int, string>
     */
    private function allowedPermissionNames(): array
    {
        $tenant = $this->getTenant();
        $allowedPermissionNames = app(EntitlementService::class)->getAllowedPermissionsForTenant($tenant->id);

        return Permission::whereIn('name', $allowedPermissionNames)->pluck('name')->values()->all();
    }
}
