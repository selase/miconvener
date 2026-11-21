<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\StoreUserRequest;
use App\Http\Requests\User\UpdateUserRequest;
use App\Libraries\Helper;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class UsersController extends Controller
{
    public function __construct()
    {
        $this->middleware('can:access-superadmin-dashboard');
    }

    /**
     * Display a listing of the resource.
     *
     * @return Response
     */
    public function index(): Factory|View
    {
        $this->authorize('access-superadmin-dashboard');
        $this->authorize('read user');

        $breadcrumbs = [
            ['link' => url('dashboard'), 'name' => __('Home')],
            ['name' => __('Users')],
        ];

        $isSuperAdmin = \Illuminate\Support\Facades\Gate::allows('access-superadmin-dashboard');

        $roles = Role::all();
        if (! $isSuperAdmin) {
            $roles = $roles->reject(fn ($role): bool => $role->name === 'Superadmin');
        }
        $statuses = User::STATUSES;
        $tenants = Tenant::query()->select('id', 'name')->latest()->get();

        return view('admin.user-management.users.index', ['breadcrumbs' => $breadcrumbs, 'roles' => $roles, 'statuses' => $statuses, 'tenants' => $tenants]);
    }

    public function getAllUsers(Request $request): JsonResponse
    {
        $this->authorize('access-superadmin-dashboard');
        $this->authorize('read user');

        $columns = [
            0 => 'uuid',
            1 => 'first_name',
            2 => 'roles.name',
            3 => 'last_login_at',
            4 => 'created_at',
            5 => 'action',
        ];

        $query = User::query();

        $isSuperAdmin = \Illuminate\Support\Facades\Gate::allows('access-superadmin-dashboard');

        // 1. If not Superadmin, filter out Superadmin users
        if (! $isSuperAdmin) {
            $query->whereHas('roles', function ($q): void {
                $q->where('name', '!=', 'Superadmin');
            });

            // 2. If not Superadmin, only show users belonging to the same tenant(s)
            $tenantId = app(\App\Services\Tenancy\TenantContext::class)->activeTenantId();
            if ($tenantId) {
                $query->where('tenant_id', $tenantId);
            }
        }

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

            $roleUserIds = User::idsWithRoleNameLike((string) $search);

            $users = $query->with(['roles:id,name'])
                ->where(function ($query) use ($search, $roleUserIds): void {
                    $query->where('first_name', 'ilike', "%{$search}%")
                        ->orWhere('last_name', 'ilike', "%{$search}%")
                        ->orWhere('email', 'ilike', "%{$search}%")
                        ->orWhereRaw('CAST(last_login_at AS TEXT) LIKE ?', ["%{$search}%"])
                        ->orWhereRaw('CAST(created_at AS TEXT) LIKE ?', ["%{$search}%"])
                        ->orWhereIn('id', $roleUserIds);
                })
                ->offset($start)
                ->limit($limit)
                ->orderBy($order, $dir)
                ->get();

            $totalFiltered = $query->count();
        }

        $data = [];

        if ($users->isNotEmpty()) {
            foreach ($users as $user) {
                $action = '';

                if (Auth::user()->can('update user')) {
                    $action .= '<a href="javascript:void(0)" onclick="updateUser(\''.$user->uuid.'\')" class="btn btn-icon btn-active-light-primary w-30px h-30px me-3"
                                    data-toggle="tooltip" data-placement="top" title="Edit User">
                                    <i class="fas fa-edit fs-4"></i>
                                </a>';

                    $action .= '<a href="javascript:void(0)" onclick="resendAccountPassword(\''.$user->uuid.'\')" class="btn btn-icon btn-active-light-info w-30px h-30px me-3"
                                    data-kt-permissions-table-filter="delete_row" data-toggle="tooltip" data-placement="top"  title="Send account recovery link">
                                    <i class="fas fa-key fs-4"></i>
                                </a>';
                }

                if (Auth::user()->can('impersonate user') && $user->roles->pluck('name')->first() !== 'Superadmin') {
                    $action .= '<a href="'.route('impersonation.impersonate', $user->id).'" class="btn btn-icon btn-active-light-warning w-30px h-30px me-3"
                                        data-kt-permissions-table-filter="delete_row" data-toggle="tooltip" data-placement="top"  title="Login as '.e($user->displayName()).'">
                                        <i class="fas fa-user-tag fs-4"></i>
                                    </a>';
                }

                if (Auth::user()->can('delete user') && $user->roles->pluck('name')->first() !== 'Superadmin') {
                    $action .= '<a href="javascript:void(0)" onclick="deleteData(\''.$user->uuid.'\', \'/user-management/users/\')" class="btn btn-icon btn-active-light-danger w-30px h-30px"
                                        data-kt-permissions-table-filter="delete_row" data-toggle="tooltip" data-placement="top"  title="Delete User">
                                        <i class="fas fa-trash fs-4"></i>
                                    </a>';
                }

                $user->photo
                    ? $userPhoto = Storage::url($user->photo)
                    : $userPhoto = $user->gravatar;

                // Names and emails are user-supplied, so they are escaped before
                // going into the HTML the table renders.
                $showUrl = e(route('users.show', $user->uuid));
                $client = '<div class="symbol symbol-circle symbol-50px overflow-hidden me-3">
                                <a href="'.$showUrl.'">
                                    <div class="symbol-label">
                                        <img src="'.e($userPhoto).'"
                                            alt="'.e($user->displayName()).'" class="w-100">
                                    </div>
                                </a>
                            </div>

                            <div class="d-flex flex-column">
                                <a href="'.$showUrl.'"
                                    class="text-gray-800 text-hover-primary mb-1">'.e($user->displayName()).'</a>
                                <span>'.e($user->email).'</span>
                            </div>';

                $nestedData['uuid'] = $user->uuid;
                $nestedData['client'] = $client;
                $nestedData['role'] = $user->roles->map(fn ($role): string => '<span class="badge badge-light-primary fw-bolder">'.e(ucfirst((string) $role->name)).'</span>')->implode(' ');
                $nestedData['last_login_at'] = $user->last_login_at?->diffForHumans();
                $nestedData['created_at'] = $user->created_at->format('Y-m-d');
                $nestedData['action'] = $action;

                $data[] = $nestedData;
            }
        }

        $json_data = [
            'draw' => (int) ($request->input('draw')),
            'recordsTotal' => (int) $totalData,
            'recordsFiltered' => (int) $totalFiltered,
            'data' => $data,
        ];

        return response()->json($json_data);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(): void
    {
        $this->authorize('access-superadmin-dashboard');
        $this->authorize('create user');
        //
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  Request  $request
     * @return Response
     */
    public function store(StoreUserRequest $request)
    {
        $this->authorize('access-superadmin-dashboard');
        $this->authorize('create user');

        $validatedData = $request->validated();
        $this->validateRoleScope($validatedData['roles'], $request->input('tenant_id') ?: null);

        if ($tenantId = $request->input('tenant_id')) {
            abort_unless(count($validatedData['roles']) === 1, 422, 'Choose one organisation role.');
            app(\App\Services\Tenancy\OrganisationInvitations::class)->invite(
                Tenant::query()->findOrFail($tenantId), $request->user(), $validatedData['email'],
                Role::query()->findOrFail($validatedData['roles'][0]), []
            );
        } else {
            \Illuminate\Support\Facades\DB::connection('landlord')->transaction(function () use ($validatedData, $request): void {
                $user = User::query()->create([
                    'first_name' => $validatedData['first_name'], 'last_name' => $validatedData['last_name'],
                    'email' => $validatedData['email'], 'phone_no' => $validatedData['phone_no'],
                    'password' => bcrypt(Str::random(64)), 'status' => $validatedData['status'],
                    'photo' => $request->hasFile('photo') ? Helper::processUploadedFile($request, 'photo', Date::now()->format('YmdHis'), '/users/profile', User::uploadDisk()) : null,
                ]);
                $previous = getPermissionsTeamId();
                try {
                    setPermissionsTeamId(null);
                    $user->syncRoles($validatedData['roles']);
                } finally {
                    setPermissionsTeamId($previous);
                }
                \Illuminate\Support\Facades\DB::connection('landlord')->afterCommit(fn () => Password::sendResetLink(['email' => $user->email]));
            });
        }

        return response()->json([
            'status' => 'success',
            'message' => __('locale.messages.created', ['name' => 'User']),
        ]);
    }

    /**
     * Display the specified resource.
     *
     * @return Response
     */
    public function show(string $id): Factory|View
    {
        $this->authorize('access-superadmin-dashboard');
        $this->authorize('read user');

        $breadcrumbs = [
            ['link' => url('dashboard'), 'name' => __('Home')],
            ['name' => __('locale.menu.user_management')],
            ['link' => route('users.index'), 'name' => __('locale.menu.users')],
        ];

        $user = User::findByUuid($id);

        return view('admin.user-management.users.show', ['breadcrumbs' => $breadcrumbs, 'user' => $user]);
    }

    public function edit(User $user)
    {
        $this->authorize('access-superadmin-dashboard');
        $this->authorize('update user');
        $userRoles = \Illuminate\Support\Facades\DB::connection('landlord')->table(config('permission.table_names.model_has_roles'))
            ->where('model_type', User::class)->where('model_id', (string) $user->id)
            ->when($user->tenant_id, fn ($query, $id) => $query->where('tenant_id', $id), fn ($query) => $query->whereNull('tenant_id'))
            ->pluck('role_id')->all();

        return response()->json([
            'status' => 'success',
            'data' => $user,
            'roles' => $userRoles,
        ]);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  Request  $request
     * @return Response
     */
    public function update(UpdateUserRequest $request, User $user)
    {
        $this->authorize('access-superadmin-dashboard');
        $this->authorize('update user');

        $validatedData = $request->validated();
        $user->getConnection()->transaction(function () use ($request, $user, $validatedData): void {
            $tenantIds = $user->allTenants()->pluck('tenants.id')->sort()->values()->all();
            $tenants = Tenant::query()->whereIn('id', $tenantIds)->orderBy('id')->lockForUpdate()->get();
            $user = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            abort_unless($tenantIds === $user->allTenants()->pluck('tenants.id')->sort()->values()->all(), 409, 'Organisation membership changed. Review this account again.');
            $suspending = $validatedData['status'] === User::STATUS_INACTIVE && $user->status !== User::STATUS_INACTIVE;
            if ($suspending) {
                foreach ($tenants as $tenant) {
                    if ($tenant->status !== \App\Enum\TenantStatusEnum::ACTIVE) {
                        continue;
                    }
                    $hasOtherOwner = app(\App\Services\Tenancy\OrganisationMemberships::class)->inTenant($tenant, [], fn (): bool => $tenant->users()->with('roles')
                        ->where('users.id', '!=', $user->id)->where(fn ($query) => $query->where('users.status', User::STATUS_ACTIVE)->orWhereNull('users.status'))
                        ->get()->contains(fn (User $owner): bool => $owner->hasRole('Org Superadmin')));
                    abort_unless($hasOtherOwner, 403, 'Assign another active owner or pause each affected organisation before suspending this account.');
                }
            }
            $this->validateRoleScope($validatedData['roles'], $user->tenant_id);
            $hasMembershipHistory = $user->tenant_id !== null || $user->allTenants()->exists();
            if ($hasMembershipHistory) {
                $held = \Illuminate\Support\Facades\DB::connection('landlord')->table(config('permission.table_names.model_has_roles'))
                    ->where('model_type', User::class)->where('model_id', (string) $user->id)
                    ->when($user->tenant_id, fn ($query, $id) => $query->where('tenant_id', $id), fn ($query) => $query->whereNull('tenant_id'))
                    ->pluck('role_id')->map(fn ($id): string => (string) $id)->sort()->values()->all();
                $requested = array_map('strval', $validatedData['roles']);
                sort($requested);
                abort_unless($held === $requested, 403, 'Manage organisation roles through that organisation’s Team page.');
            }

            $emailChanged = $validatedData['email'] !== $user->email;
            abort_if($emailChanged && ($user->tenant_id !== null || $user->allTenants()->exists()), 403, 'Email changes for organisation members require explicit account recovery.');

            if ($emailChanged || $suspending) {
                Password::deleteToken($user);
                if ($emailChanged) {
                    $user->email_verified_at = null;
                }
                $user->security_version = (int) $user->security_version + 1;
                $user->remember_token = Str::random(60);
                $user->tokens()->delete();
            }

            $user->first_name = $validatedData['first_name'];
            $user->last_name = $validatedData['last_name'];
            $user->email = $validatedData['email'];
            $user->phone_no = $validatedData['phone_no'];
            $user->status = $validatedData['status'];

            if ($request->hasFile('photo')) {
                // delete old photo
                if ($user->photo) {
                    Helper::deleteFile($user->photo, User::uploadDisk());
                }

                $prefix = Date::now()->format('YmdHis');
                $photoPath = Helper::processUploadedFile($request, 'photo', $prefix, '/users/profile', User::uploadDisk());
                $user->photo = $photoPath;
            }

            $user->save();

            if (! $hasMembershipHistory) {
                $previous = getPermissionsTeamId();
                try {
                    setPermissionsTeamId(null);
                    $user->syncRoles($validatedData['roles']);
                } finally {
                    setPermissionsTeamId($previous);
                }
            }
        });

        return response()->json([
            'status' => 'success',
            'message' => __('locale.messages.updated', ['name' => 'User']),
        ]);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @return Response
     */
    public function destroy(string $id)
    {
        $this->authorize('access-superadmin-dashboard');
        $this->authorize('delete user');
        (new User)->getConnection()->transaction(function () use ($id): void {
            $user = User::query()->where('uuid', $id)->lockForUpdate()->firstOrFail();
            abort_if($user->tenant_id !== null || $user->allTenants()->exists(), 403, 'Remove organisation memberships before deleting this identity.');
            abort_if(\App\Models\BadgeTemplateLicenceEvent::query()->where('actor_id', $user->id)->exists()
                || \App\Models\BadgeTemplateUse::query()->where('actor_id', $user->id)->exists()
                || \App\Models\BadgeTemplatePurchase::query()->where('terms_snapshot->requested_by', (string) $user->id)->exists(), 403, 'Retain this identity for its paid template audit history.');
            $user->delete();
        });

        return response()->json([
            'status' => __('locale.messages.deleted', ['name' => 'User']),
        ]);
    }

    /** @param array<int, mixed> $roles */
    private function validateRoleScope(array $roles, ?string $tenantId): void
    {
        foreach ($roles as $roleId) {
            $role = Role::query()->whereKey($roleId)->first();
            $tenantSafe = $role && ($role->tenant_id === $tenantId || ($role->tenant_id === null && in_array($role->name, ['Org Superadmin', 'Org Admin', 'Event Staff', 'Event Organiser'], true)));
            if (! $role || ($tenantId !== null ? ! $tenantSafe : $role->tenant_id !== null)) {
                throw \Illuminate\Validation\ValidationException::withMessages(['roles' => 'The selected role is not valid for this workspace.']);
            }
        }
    }
}
