<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\StoreUserRequest;
use App\Http\Requests\User\UpdateUserRequest;
use App\Libraries\Helper;
use App\Models\Role;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class UserController extends Controller
{
    public function index(string $subdomain): \Illuminate\Contracts\View\Factory|\Illuminate\Contracts\View\View
    {
        $this->authorize('read user');
        $tenant = $this->getTenant();

        $breadcrumbs = [
            ['link' => route('tenant.dashboard', ['subdomain' => $tenant->slug]), 'name' => __('Home')],
            ['name' => __('Users')],
        ];

        // Only show roles relevant to this tenant
        $roles = Role::where(function ($q) use ($tenant): void {
            $q->whereNull('tenant_id')
                ->orWhere('tenant_id', $tenant->id);
        })->get();

        $isSuperAdmin = \Illuminate\Support\Facades\Gate::allows('access-superadmin-dashboard');

        // Exclude Superadmin from selection if not superadmin
        if (! $isSuperAdmin) {
            $roles = $roles->reject(fn ($role): bool => $role->isSystemRole() && $role->name === 'Superadmin');
        }

        $statuses = User::STATUSES;
        $tenants = [$tenant]; // Only this tenant

        return view('admin.user-management.users.index', [
            'breadcrumbs' => $breadcrumbs,
            'roles' => $roles,
            'statuses' => $statuses,
            'tenants' => $tenants,
        ]);
    }

    public function getAllUsers(Request $request, string $subdomain): JsonResponse
    {
        $this->authorize('read user');
        $tenant = $this->getTenant();

        $columns = [
            0 => 'uuid',
            1 => 'first_name',
            2 => 'roles.name',
            3 => 'last_login_at',
            4 => 'created_at',
            5 => 'action',
        ];

        $query = User::where('tenant_id', $tenant->id);

        $totalData = $query->count();
        $totalFiltered = $totalData;

        $limit = $request->input('length');
        $start = $request->input('start');
        $order = $columns[$request->input('order.0.column')];
        $dir = $request->input('order.0.dir');

        if (empty($request->input('search.value'))) {
            $users = $query->with(['roles:id,name'])
                ->offset($start)
                ->limit($limit)
                ->orderBy($order, $dir)
                ->get();
        } else {
            $search = $request->input('search.value');
            $users = $query->with(['roles:id,name'])
                ->where(function ($query) use ($search): void {
                    $query->where('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                })
                ->offset($start)
                ->limit($limit)
                ->orderBy($order, $dir)
                ->get();

            $totalFiltered = $query->count();
        }

        $data = [];
        foreach ($users as $user) {
            $action = '';
            // Basic actions for tenant admin
            if (auth()->user()->can('update user')) {
                $action .= '<a href="javascript:void(0)" onclick="updateUser(\''.$user->uuid.'\')" class="btn btn-icon btn-active-light-primary w-30px h-30px me-3"
                                    data-toggle="tooltip" data-placement="top" title="Edit User">
                                    <i class="fas fa-edit fs-4"></i>
                                </a>';
            }

            if (auth()->user()->can('delete user')) {
                $action .= '<a href="javascript:void(0)" onclick="deleteData(\''.$user->uuid.'\', \'/users/\')" class="btn btn-icon btn-active-light-danger w-30px h-30px"
                                    data-toggle="tooltip" data-placement="top" title="Delete User">
                                    <i class="fas fa-trash fs-4"></i>
                                </a>';
            }

            $userPhoto = $user->photo ? \Illuminate\Support\Facades\Storage::url($user->photo) : $user->gravatar;

            $client = '<div class="symbol symbol-circle symbol-50px overflow-hidden me-3">
                            <a href="javascript:void(0)">
                                <div class="symbol-label">
                                    <img src="'.$userPhoto.'" alt="'.$user->displayName().'" class="w-100">
                                </div>
                            </a>
                        </div>
                        <div class="d-flex flex-column">
                            <a href="javascript:void(0)" class="text-gray-800 text-hover-primary mb-1">'.$user->displayName().'</a>
                            <span>'.$user->email.'</span>
                        </div>';

            $data[] = [
                'uuid' => $user->uuid,
                'client' => $client,
                'role' => $user->roles->pluck('name')->first(),
                'last_login_at' => $user->last_login_at?->diffForHumans(),
                'created_at' => $user->created_at->format('Y-m-d'),
                'action' => $action,
            ];
        }

        return response()->json([
            'draw' => (int) $request->input('draw'),
            'recordsTotal' => (int) $totalData,
            'recordsFiltered' => (int) $totalFiltered,
            'data' => $data,
        ]);
    }

    public function store(StoreUserRequest $request, string $subdomain): JsonResponse
    {
        $this->authorize('create user');
        $tenant = $this->getTenant();
        $validated = $request->validated();

        $role = Role::where('id', $validated['role'])
            ->where(function ($q) use ($tenant): void {
                $q->whereNull('tenant_id')
                    ->orWhere('tenant_id', $tenant->id);
            })->firstOrFail();

        if ($role->isSystemRole() && $role->name === 'Superadmin') {
            abort(403, 'Cannot assign Superadmin role.');
        }

        return DB::transaction(function () use ($validated, $tenant, $role) {
            $password = Helper::generateRandomPassword();
            $user = User::create([
                'first_name' => $validated['first_name'],
                'last_name' => $validated['last_name'],
                'email' => $validated['email'],
                'phone_no' => $validated['phone_no'],
                'password' => bcrypt($password),
                'status' => $validated['status'],
                'tenant_id' => $tenant->id,
            ]);

            setPermissionsTeamId($tenant->id);
            $user->assignRole($role);
            $user->tenants()->attach($tenant->id);

            // Send email
            \Illuminate\Support\Facades\Mail::to($user->email)
                ->queue(new \App\Mail\Users\SendAccountDetails($user->first_name, $user->email, $password));

            return response()->json([
                'status' => 'success',
                'message' => __('locale.messages.created', ['name' => 'User']),
            ]);
        });
    }

    public function edit(string $subdomain, User $user): JsonResponse
    {
        $this->authorize('update user');
        $tenant = $this->getTenant();

        // Ensure user belongs to this tenant
        if ($user->tenant_id !== $tenant->id) {
            abort(403);
        }

        return response()->json([
            'data' => $user,
            'role' => [
                'id' => $user->roles->first()?->id,
            ],
        ]);
    }

    public function update(UpdateUserRequest $request, string $subdomain, User $user): JsonResponse
    {
        $this->authorize('update user');
        $tenant = $this->getTenant();
        $validated = $request->validated();

        // Ensure user belongs to this tenant
        if ($user->tenant_id !== $tenant->id) {
            abort(403);
        }

        $user->update([
            'first_name' => $validated['first_name'],
            'last_name' => $validated['last_name'],
            'email' => $validated['email'],
            'phone_no' => $validated['phone_no'],
            'status' => $validated['status'],
        ]);

        $role = Role::where('id', $validated['role'])
            ->where(function ($q) use ($tenant): void {
                $q->whereNull('tenant_id')
                    ->orWhere('tenant_id', $tenant->id);
            })->firstOrFail();

        setPermissionsTeamId($tenant->id);
        $user->syncRoles([$role]);

        return response()->json([
            'status' => 'success',
            'message' => __('locale.messages.updated', ['name' => 'User']),
        ]);
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
