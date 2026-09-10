<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Libraries\Helper;
use App\Mail\Users\SendAccountDetails;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Propaganistas\LaravelPhone\Rules\Phone;
use Spatie\Permission\Models\Role;

final class TeamController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(): void
    {
        //
    }

    public function getAllTeams(Request $request, string|int $tenantId): JsonResponse
    {
        // Resolve tenant ID if UUID is passed
        if (! is_int($tenantId) && \Illuminate\Support\Str::isUuid($tenantId)) {
            $tenant = Tenant::findByUuid($tenantId);
            if (! $tenant instanceof Tenant) {
                return response()->json(['error' => 'Tenant not found'], 404);
            }
            $tenantId = $tenant->id;
        }

        $columns = [
            0 => 'uuid',
            1 => 'first_name',
            2 => 'roles.name',
            3 => 'created_at',
            4 => 'action',
        ];

        $totalData = User::query()->where('tenant_id', $tenantId)->count();

        $totalFiltered = $totalData;

        $limit = $request->input('length');
        $start = $request->input('start');
        $orderColumn = $request->input('order.0.column', 0);
        $order = $columns[$orderColumn] ?? $columns[0];
        $dir = $request->input('order.0.dir');

        if (empty($request->input('search.value'))) {
            $users = User::query()
                ->with(['roles:id,name'])
                ->where('tenant_id', $tenantId)
                ->offset($start)
                ->limit($limit)
                ->orderBy($order, $dir)
                ->get();
        } else {
            $search = $request->input('search.value');

            $query = User::query()
                ->with(['roles:id,name'])
                ->where('tenant_id', $tenantId)
                ->where(function ($q) use ($search): void {
                    $q->where('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('created_at', 'like', "%{$search}%")
                        ->orWhereHas('roles', function ($rq) use ($search): void {
                            $rq->where('name', 'like', "%{$search}%");
                        });
                });

            $users = $query->clone()
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
                    $action .= '<a href="javascript:void(0)" onclick="resendAccountPassword(\''.$user->uuid.'\')" class="btn btn-icon btn-active-light-info w-30px h-30px"
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
                $nestedData['role'] = $user->roles->map(fn ($role): string => '<span class="badge badge-light-primary fw-bolder me-1">'.ucfirst((string) $role->name).'</span>')->implode(' ');
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
        $this->authorize('create team');

        $tenant = Tenant::findByUuid($id);
        $roles = Role::all();

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
        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', Rule::unique('users', 'email')],
            'phone_number' => ['required', (new Phone)->international()->country('GH')],
            'roles' => ['required', 'array'],
            'status' => ['required', 'string'],
            'photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,svg', 'max:2048'],
        ]);

        $tenant = Tenant::findByUuid($id);

        $user = new User();
        $user->first_name = $validated['first_name'];
        $user->last_name = $validated['last_name'];
        $user->email = $validated['email'];
        $user->phone_no = $validated['phone_number'];
        $password = Helper::generateRandomPassword();
        $user->password = bcrypt($password);
        $user->status = $validated['status'];
        $user->tenant_id = $tenant->id;

        if ($request->hasFile('photo')) {
            config('app.env') === 'production'
                ? $disk = 's3'
                : $disk = 'public';

            $photoPath = Helper::processUploadedFile($request, 'photo', 'user_photo', '/users/profile', $disk);
            $user->photo = $photoPath;
        }

        $user->save();

        // Assign user to role
        $user->syncRoles($validated['roles']);

        // attach user to their tenants
        $user->tenants()->sync([$tenant->id]);

        $loginUrl = $tenant->url('/login');

        // Send email to user
        Mail::to($user->email)
            ->queue(new SendAccountDetails(
                $user->first_name,
                $user->email,
                $password,
                $loginUrl,
                $tenant->name
            ));

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
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id): void
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id): void
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id): void
    {
        //
    }
}
