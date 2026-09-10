<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\StoreUserRequest;
use App\Http\Requests\User\UpdateUserRequest;
use App\Libraries\Helper;
use App\Mail\Users\SendAccountDetails;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;

final class UsersController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return Response
     */
    public function index(): Factory|View
    {
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

            $users = $query->with(['roles:id,name'])
                ->where(function ($query) use ($search): void {
                    $query->where('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('last_login_at', 'like', "%{$search}%")
                        ->orWhere('created_at', 'like', "%{$search}%");
                })
                ->orWhereHas('roles', function ($query) use ($search): void {
                    $query->where('name', 'like', "%{$search}%"); // Search in role names
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
                                    data-kt-permissions-table-filter="delete_row" data-toggle="tooltip" data-placement="top"  title="Resend Account Password">
                                    <i class="fas fa-key fs-4"></i>
                                </a>';
                }

                if (Auth::user()->can('impersonate user') && $user->roles->pluck('name')->first() !== 'Superadmin') {
                    $action .= '<a href="'.route('impersonation.impersonate', $user->id).'" class="btn btn-icon btn-active-light-warning w-30px h-30px me-3"
                                        data-kt-permissions-table-filter="delete_row" data-toggle="tooltip" data-placement="top"  title="Login as '.$user->displayName().'">
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

                $client = '<div class="symbol symbol-circle symbol-50px overflow-hidden me-3">
                                <a href="javascript:void(0)">
                                    <div class="symbol-label">
                                        <img src="'.$userPhoto.'"
                                            alt="'.$user->displayName().'" class="w-100">
                                    </div>
                                </a>
                            </div>

                            <div class="d-flex flex-column">
                                <a href="javascript:void(0)"
                                    class="text-gray-800 text-hover-primary mb-1">'.$user->displayName().'</a>
                                <span>'.$user->email.'</span>
                            </div>';

                $nestedData['uuid'] = $user->uuid;
                $nestedData['client'] = $client;
                $nestedData['role'] = $user->roles->map(fn ($role): string => '<span class="badge badge-light-primary fw-bolder">'.ucfirst((string) $role->name).'</span>')->implode(' ');
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
        $this->authorize('create user');

        $validatedData = $request->validated();

        $password = Helper::generateRandomPassword();

        $user = User::query()->create([
            'first_name' => $validatedData['first_name'],
            'last_name' => $validatedData['last_name'],
            'email' => $validatedData['email'],
            'phone_no' => $validatedData['phone_no'],
            'password' => bcrypt($password),
            'status' => $validatedData['status'],
            'photo' => $request->hasFile('photo') ? Helper::processUploadedFile($request, 'photo', Date::now()->format('YmdHis'), '/users/profile') : null,
            'tenant_id' => $request->tenant_id ?? null,
        ]);

        if (! empty($request->tenant_id)) {
            setPermissionsTeamId($request->tenant_id);
            $user->tenants()->sync([$request->tenant_id]);
        }

        // Assign user to role(s)
        $user->syncRoles($validatedData['roles']);

        $tenant = ! empty($request->tenant_id) ? Tenant::find($request->tenant_id) : null;
        $loginUrl = $tenant ? $tenant->url('/login') : route('login');

        // Send email to user
        Mail::to($user->email)
            ->queue(new SendAccountDetails(
                $user->first_name,
                $user->email,
                $password,
                $loginUrl,
                $tenant?->name
            ));

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
        $userRoles = $user->roles()->pluck('id')->toArray();

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
        $this->authorize('update user');

        $validatedData = $request->validated();

        $user->first_name = $validatedData['first_name'];
        $user->last_name = $validatedData['last_name'];
        $user->email = $validatedData['email'];
        $user->phone_no = $validatedData['phone_no'];
        $user->status = $validatedData['status'];

        if ($request->hasFile('photo')) {
            // delete old photo
            if ($user->photo) {
                Helper::deleteFile($user->photo);
            }

            $prefix = Date::now()->format('YmdHis');
            $photoPath = Helper::processUploadedFile($request, 'photo', $prefix, '/users/profile');
            $user->photo = $photoPath;
        }

        if ($user->tenant_id) {
            setPermissionsTeamId($user->tenant_id);
        }

        $user->save();

        // update user role(s)
        if (isset($validatedData['roles'])) {
            $user->syncRoles($validatedData['roles']);
        }

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
        User::findByUuid($id)->delete();

        return response()->json([
            'status' => __('locale.messages.deleted', ['name' => 'User']),
        ]);
    }
}
