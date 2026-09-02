<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Role\UpdateRoleRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

final class RoleController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(): View
    {
        $breadcrumbs = [
            ['link' => url('dashboard'), 'name' => __('Home')],
            ['name' => __('User Management')],
            ['name' => __('Roles')],
        ];

        $roles = Role::query()
            ->with(['permissions'])
            ->select('roles.*')
            ->selectSub(function ($query): void {
                $query->from(config('permission.table_names.model_has_roles'))
                    ->whereColumn(config('permission.column_names.role_pivot_key') ?? 'role_id', 'roles.id')
                    ->where('model_type', \App\Models\User::class)
                    ->selectRaw('count(*)');
            }, 'users_count')
            ->get();

        return view('admin.user-management.roles.index', ['breadcrumbs' => $breadcrumbs, 'roles' => $roles]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(): void
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): void
    {
        //
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     */
    public function show($id): void
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(int $id): View
    {
        // $this->authorize('update role');

        $breadcrumbs = [
            ['link' => url('dashboard'), 'name' => __('Home')],
            ['name' => __('User Management')],
            ['link' => route('roles.index'), 'name' => __('locale.labels.roles')],
            ['name' => __('Edit')],
        ];

        $role = Role::findById($id);
        $role->load('permissions');

        // retrieve permission with their associated categories
        $permissions = Permission::all()->groupBy('category');

        // retrieve existing role permissions
        $existing_permissions = $role->permissions;

        return view('admin.user-management.roles.edit', ['breadcrumbs' => $breadcrumbs, 'role' => $role, 'permissions' => $permissions, 'existing_permissions' => $existing_permissions]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateRoleRequest $request, int|string $id): RedirectResponse
    {
        // $this->authorize('update role');

        $request->validated();

        $role = Role::findById($id);
        $role->update([
            'name' => $request->name,
        ]);

        // sync permissions
        $role->syncPermissions($request->permissions);

        return to_route('roles.index')->with([
            'status' => 'success',
            'message' => __('locale.messages.updated', ['name' => 'Role']),
        ]);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     */
    public function destroy($id): void
    {
        //
    }
}
