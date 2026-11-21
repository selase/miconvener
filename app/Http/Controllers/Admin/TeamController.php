<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Propaganistas\LaravelPhone\Rules\Phone;

final class TeamController extends Controller
{
    public function __construct()
    {
        $this->middleware('can:access-superadmin-dashboard');
    }

    /**
     * Display a listing of the resource.
     */
    public function index(): void
    {
        $this->authorize('access-superadmin-dashboard');
        $this->authorize('read team');
        //
    }

    public function getAllTeams(Request $request, string|int $tenantId): JsonResponse
    {
        $this->authorize('access-superadmin-dashboard');
        $this->authorize('read team');
        // Resolve tenant ID if UUID is passed
        if (! is_int($tenantId) && \Illuminate\Support\Str::isUuid($tenantId)) {
            $tenant = Tenant::findByUuid($tenantId);
            if (! $tenant instanceof Tenant) {
                return response()->json(['error' => 'Tenant not found'], 404);
            }
            $tenantId = $tenant->id;
        }

        $previousTeam = getPermissionsTeamId();
        try {
            setPermissionsTeamId($tenantId);
            $columns = [
                0 => 'uuid',
                1 => 'first_name',
                2 => 'roles.name',
                3 => 'created_at',
                4 => 'action',
            ];

            $totalData = User::query()->whereHas('allTenants', fn ($query) => $query->where('tenants.id', $tenantId)->whereIn('tenant_user.status', ['active', 'suspended']))->count();

            $totalFiltered = $totalData;

            $limit = $request->input('length');
            $start = $request->input('start');
            $orderColumn = $request->input('order.0.column', 0);
            $order = $columns[$orderColumn] ?? $columns[0];
            $dir = $request->input('order.0.dir');

            if (empty($request->input('search.value'))) {
                $users = User::query()
                    ->with(['roles:id,name'])
                    ->whereHas('allTenants', fn ($query) => $query->where('tenants.id', $tenantId)->whereIn('tenant_user.status', ['active', 'suspended']))
                    ->offset($start)
                    ->limit($limit)
                    ->orderBy($order, $dir)
                    ->get();
            } else {
                $search = $request->input('search.value');

                $query = User::query()
                    ->with(['roles:id,name'])
                    ->whereHas('allTenants', fn ($query) => $query->where('tenants.id', $tenantId)->whereIn('tenant_user.status', ['active', 'suspended']))
                    ->where(function ($q) use ($search): void {
                        $q->where('first_name', 'ilike', "%{$search}%")
                            ->orWhere('last_name', 'ilike', "%{$search}%")
                            ->orWhere('email', 'ilike', "%{$search}%")
                            ->orWhereRaw('CAST(created_at AS TEXT) LIKE ?', ["%{$search}%"])
                            ->orWhereIn('id', User::idsWithRoleNameLike((string) $search));
                    });

                $users = $query->clone()
                    ->offset($start)
                    ->limit($limit)
                    ->orderBy($order, $dir)
                    ->get();

                $totalFiltered = $query->count();
            }

        } finally {
            setPermissionsTeamId($previousTeam);
        }

        $data = [];

        if ($users->isNotEmpty()) {
            foreach ($users as $user) {
                $action = '';

                if (Auth::user()->can('update user')) {
                    $action .= '<a href="javascript:void(0)" onclick="resendAccountPassword(\''.$user->uuid.'\')" class="btn btn-icon btn-active-light-info w-30px h-30px"
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

                $user->photo
                    ? $userPhoto = Storage::url($user->photo)
                    : $userPhoto = $user->gravatar;

                $client = '<div class="symbol symbol-circle symbol-50px overflow-hidden me-3">
                                <a href="javascript:void(0)">
                                    <div class="symbol-label">
                                        <img src="'.$userPhoto.'"
                                            alt="'.e($user->displayName()).'" class="w-100">
                                    </div>
                                </a>
                            </div>

                            <div class="d-flex flex-column">
                                <a href="javascript:void(0)"
                                    class="text-gray-800 text-hover-primary mb-1">'.e($user->displayName()).'</a>
                                <span>'.e($user->email).'</span>
                            </div>';

                $nestedData['uuid'] = $user->uuid;
                $nestedData['client'] = $client;
                $nestedData['role'] = $user->roles->map(fn ($role): string => '<span class="badge badge-light-primary fw-bolder me-1">'.e(ucfirst((string) $role->name)).'</span>')->implode(' ');
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
    public function create(string $id): View
    {
        $this->authorize('access-superadmin-dashboard');
        $this->authorize('create team');

        $tenant = Tenant::findByUuidOrFail($id);
        $roles = Role::query()->where(function ($query) use ($tenant): void {
            $query->where('tenant_id', $tenant->id)->orWhere(function ($query): void {
                $query->whereNull('tenant_id')->whereIn('name', ['Org Superadmin', 'Org Admin', 'Event Staff', 'Event Organiser']);
            });
        })->get();

        $breadcrumbs = [
            ['link' => route('dashboard'), 'name' => __('Home')],
            ['link' => route('tenants.index'), 'name' => __('Tenants')],
            ['link' => route('tenants.show', $tenant->uuid), 'name' => $tenant->name],
            ['name' => __('Team')],
        ];

        return view('admin.tenants.team.create', [
            'tenant' => $tenant,
            'roles' => $roles,
            'breadcrumbs' => $breadcrumbs,
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request, string $id)
    {
        $this->authorize('access-superadmin-dashboard');
        $this->authorize('create team');
        $tenant = Tenant::findByUuidOrFail($id);
        $request->merge(['phone_number' => \App\Support\PhoneNumber::withInternationalPlus($request->input('phone_number'))]);
        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email'],
            'phone_number' => ['required', (new Phone)->international()->country('GH')],
            'roles' => ['required', 'array', 'min:1', function (string $attribute, mixed $value, Closure $fail) use ($tenant): void {
                foreach ($value as $roleId) {
                    $role = Role::query()->whereKey($roleId)->first();
                    if (! $role || ! ($role->tenant_id === $tenant->id || ($role->tenant_id === null && in_array($role->name, ['Org Superadmin', 'Org Admin', 'Event Staff', 'Event Organiser'], true)))) {
                        $fail('The selected role is not valid for this workspace.');
                    }
                }
            }],
            'status' => ['required', 'string'],
            'photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,svg', 'max:2048'],
        ]);

        abort_unless(count($validated['roles']) === 1, 422, 'Choose one organisation role.');
        app(\App\Services\Tenancy\OrganisationInvitations::class)->invite(
            $tenant, $request->user(), $validated['email'], Role::query()->findOrFail($validated['roles'][0]), []
        );

        return to_route('tenants.show', $id)->with([
            'status' => 'success',
            'message' => __('locale.messages.created', ['name' => 'Team member']),
        ]);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id): void
    {
        $this->authorize('access-superadmin-dashboard');
        $this->authorize('read team');
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id): void
    {
        $this->authorize('access-superadmin-dashboard');
        $this->authorize('update team');
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id): void
    {
        $this->authorize('access-superadmin-dashboard');
        $this->authorize('update team');
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id): void
    {
        $this->authorize('access-superadmin-dashboard');
        $this->authorize('delete team');
        //
    }
}
