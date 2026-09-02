<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class RoleController extends Controller
{
    public function __construct()
    {
        $this->middleware(['can:access-superadmin-dashboard']);
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        if ($request->ajax()) {
            $columns = [
                0 => 'id',
                1 => 'name',
                2 => 'guard_name',
                3 => 'created_at',
                4 => 'action',
            ];

            $limit = $request->input('length');
            $start = $request->input('start');
            $order = $columns[$request->input('order.0.column')] ?? 'created_at';
            $dir = $request->input('order.0.dir') ?? 'desc';
            $searchValue = $request->input('search.value');

            $query = \App\Models\Role::query();

            // Filter by tenant if provided, otherwise default to global (null)
            if ($request->has('tenant_id') && $request->tenant_id !== 'all') {
                $query->where('tenant_id', $request->tenant_id);
            } elseif (! $request->has('tenant_id')) {
                $query->whereNull('tenant_id');
            }

            $totalData = $query->count();
            $totalFiltered = $totalData;

            if (! empty($searchValue)) {
                $query->where(function ($q) use ($searchValue): void {
                    $q->where('name', 'like', "%{$searchValue}%")
                        ->orWhere('guard_name', 'like', "%{$searchValue}%");
                });
                $totalFiltered = $query->count();
            }

            $roles = $query->when($limit !== null && $limit !== -1, fn ($q) => $q->limit($limit))
                ->when($start !== null, fn ($q) => $q->offset($start))
                ->orderBy($order, $dir)
                ->get();

            $data = [];
            foreach ($roles as $role) {
                // Actions (Edit/Delete)
                $action = '<a href="'.route('roles.edit', $role->id).'" class="btn btn-icon btn-active-light-primary w-30px h-30px" title="Edit">
                                <i class="fas fa-edit fs-4"></i>
                           </a>';

                // Nest data
                $nestedData['id'] = $role->id;
                $nestedData['name'] = $role->name;
                $nestedData['guard_name'] = '<span class="badge badge-light-primary">'.$role->guard_name.'</span>';
                $nestedData['created_at'] = $role->created_at->format('Y-m-d H:i:s');
                $nestedData['action'] = $action;

                $data[] = $nestedData;
            }

            return response()->json([
                'draw' => (int) $request->input('draw'),
                'recordsTotal' => (int) $totalData,
                'recordsFiltered' => (int) $totalFiltered,
                'data' => $data,
            ]);
        }

        $tenants = \App\Models\Tenant::all();

        return view('admin.roles.index', ['tenants' => $tenants]);
    }

    /**
     * Show the form for creating a new resource.
     */
    /**
     * Show the form for creating a new resource.
     */
    public function create(): \Illuminate\Contracts\View\Factory|\Illuminate\Contracts\View\View
    {
        $permissions = \App\Models\Permission::all();
        $tenants = \App\Models\Tenant::all();

        return view('admin.roles.create', ['permissions' => $permissions, 'tenants' => $tenants]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('roles')->where(fn($query) => $query->where('tenant_id', $request->tenant_id)),
            ],
            'tenant_id' => 'nullable|exists:tenants,id',
            'permissions' => 'array',
            'permissions.*' => 'exists:permissions,name',
        ]);

        $role = \App\Models\Role::create([
            'name' => $validated['name'],
            'guard_name' => 'web',
            'tenant_id' => $validated['tenant_id'] ?? null,
        ]);

        if (! empty($validated['permissions'])) {
            // If it's a tenant role, we should strictly set the team ID for syncing
            if ($role->tenant_id) {
                setPermissionsTeamId($role->tenant_id);
            }
            $role->syncPermissions($validated['permissions']);
        }

        if ($request->wantsJson()) {
            return response()->json(['message' => 'Role created successfully', 'redirect' => route('roles.index')]);
        }

        return redirect()->route('roles.index')->with('success', 'Role created successfully');
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id): void
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id): \Illuminate\Contracts\View\Factory|\Illuminate\Contracts\View\View
    {
        $role = \App\Models\Role::findOrFail($id);
        $permissions = \App\Models\Permission::all();
        $tenants = \App\Models\Tenant::all();

        return view('admin.roles.edit', ['role' => $role, 'permissions' => $permissions, 'tenants' => $tenants]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $role = \App\Models\Role::findOrFail($id);

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('roles')->ignore($role->id)->where(fn($query) => $query->where('tenant_id', $role->tenant_id)),
            ],
            'permissions' => 'array',
            'permissions.*' => 'exists:permissions,name',
        ]);

        $role->update([
            'name' => $validated['name'],
        ]);

        if (isset($validated['permissions'])) {
            $role->syncPermissions($validated['permissions']);
        } else {
            // If no permissions sent (checkboxes unchecked), empty the permissions
            $role->syncPermissions([]);
        }

        if ($request->wantsJson()) {
            return response()->json(['message' => 'Role updated successfully', 'redirect' => route('roles.index')]);
        }

        return redirect()->route('roles.index')->with('success', 'Role updated successfully');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id): void
    {
        //
    }
}
