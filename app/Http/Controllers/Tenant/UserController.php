<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\StoreTeamMemberRequest;
use App\Http\Requests\Tenant\UpdateTeamMemberRequest;
use App\Libraries\Helper;
use App\Mail\Users\SendAccountDetails;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

final class UserController extends Controller
{
    public function index(string $subdomain): Response
    {
        $this->authorize('read user');
        $tenant = $this->getTenant();

        $users = User::where('tenant_id', $tenant->id)
            ->with('roles:id,name')
            ->orderByDesc('created_at')
            ->paginate(20)
            ->through(fn (User $user): array => [
                'uuid' => $user->uuid,
                'name' => $user->displayName(),
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'email' => $user->email,
                'phone_no' => $user->phone_no,
                'avatar' => $user->photo ? Storage::url($user->photo) : $user->gravatar,
                'role' => $user->roles->pluck('name')->first(),
                'role_id' => $user->roles->first()?->id,
                'status' => $user->status,
                'last_login_at' => $user->last_login_at?->diffForHumans(),
                'created_at' => $user->created_at->format('Y-m-d'),
            ]);

        return Inertia::render('Tenant/Team/Index', [
            'users' => $users,
            'roles' => $this->availableRoles(),
            'statuses' => User::STATUSES,
        ]);
    }

    public function store(StoreTeamMemberRequest $request, string $subdomain): RedirectResponse
    {
        $tenant = $this->getTenant();
        $validated = $request->validated();

        $role = Role::findById($validated['role']);

        DB::transaction(function () use ($validated, $tenant, $role): void {
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

            Mail::to($user->email)->queue(new SendAccountDetails($user->first_name, $user->email, $password));
        });

        return redirect()->route('tenant.users.index', ['subdomain' => $subdomain])
            ->with('success', __('locale.messages.created', ['name' => 'Team member']));
    }

    public function update(UpdateTeamMemberRequest $request, string $subdomain, User $user): RedirectResponse
    {
        $tenant = $this->getTenant();

        if ($user->tenant_id !== $tenant->id) {
            abort(403);
        }

        $validated = $request->validated();

        $user->update([
            'first_name' => $validated['first_name'],
            'last_name' => $validated['last_name'],
            'email' => $validated['email'],
            'phone_no' => $validated['phone_no'],
            'status' => $validated['status'],
        ]);

        $role = Role::findById($validated['role']);

        setPermissionsTeamId($tenant->id);
        $user->syncRoles([$role]);

        return redirect()->route('tenant.users.index', ['subdomain' => $subdomain])
            ->with('success', __('locale.messages.updated', ['name' => 'Team member']));
    }

    public function destroy(string $subdomain, User $user): RedirectResponse
    {
        $this->authorize('delete user');
        $tenant = $this->getTenant();

        if ($user->tenant_id !== $tenant->id) {
            abort(403);
        }

        if ($user->id === auth()->id()) {
            return back()->with('error', 'You cannot remove your own account.');
        }

        $user->tenants()->detach($tenant->id);
        $user->delete();

        return redirect()->route('tenant.users.index', ['subdomain' => $subdomain])
            ->with('success', __('locale.messages.deleted', ['name' => 'Team member']));
    }

    /**
     * @return array<int, array{id: int, name: string}>
     */
    private function availableRoles(): array
    {
        $tenant = $this->getTenant();

        $roles = Role::where(function ($query) use ($tenant): void {
            $query->whereNull('tenant_id')
                ->orWhere('tenant_id', $tenant->id);
        })->get();

        if (! Gate::allows('access-superadmin-dashboard')) {
            $roles = $roles->reject(fn ($role): bool => $role->isSystemRole() && $role->name === 'Superadmin');
        }

        return $roles->map(fn ($role): array => [
            'id' => $role->id,
            'name' => $role->name,
        ])->values()->all();
    }

    private function getTenant(): Tenant
    {
        $tenant = app(TenantContext::class)->getTenant();
        if (! $tenant) {
            abort(403, 'Tenant context not resolved.');
        }

        return $tenant;
    }
}
