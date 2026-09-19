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
use App\Services\Authorization\PermissionCeiling;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
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
        $actorCanManageOwners = $this->canManageOrgSuperadmins();

        $users = User::where('tenant_id', $tenant->id)
            ->with(['roles:id,name', 'roles.permissions:id,name'])
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
                'can_edit' => ($actorCanManageOwners || ! $user->roles->contains('name', 'Org Superadmin'))
                    && $this->withinReach($user),
                'can_remove' => ($actorCanManageOwners || ! $user->roles->contains('name', 'Org Superadmin'))
                    && $this->withinReach($user)
                    && $user->id !== auth()->id(),
                // Your own role is never yours to change.
                'can_change_role' => $user->id !== auth()->id(),
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

        $limit = $tenant->featureLimitValue('team_seats');
        if ($limit !== null && $tenant->users()->count() >= $limit) {
            $message = "Your plan allows {$limit} team member(s). Remove an existing member, or upgrade your plan, to invite another.";

            return redirect()->back()->withErrors(['email' => $message]);
        }

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

            $loginUrl = $tenant->url('/login');

            Mail::to($user->email)->queue(new SendAccountDetails(
                $user->first_name,
                $user->email,
                $password,
                $loginUrl,
                $tenant->name
            ));
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

        if ($this->wouldRemoveFinalActiveOrgSuperadmin($user, $validated['role'], $validated['status'], $tenant->id)) {
            return back()->withErrors(['role' => 'Assign another active Org Superadmin before changing the final owner.']);
        }

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

        if ($this->userHasTenantRole($user, 'Org Superadmin', $tenant->id) && ! $this->canManageOrgSuperadmins()) {
            abort(403);
        }

        if (! $this->withinReach($user)) {
            abort(403);
        }

        if ($user->id === auth()->id()) {
            return back()->with('error', 'You cannot remove your own account.');
        }

        if ($this->isFinalActiveOrgSuperadmin($user, $tenant->id)) {
            return back()->with('error', 'Assign another active Org Superadmin before removing the final owner.');
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

        $roles = Role::with('permissions')->where(function ($query) use ($tenant): void {
            $query->whereNull('tenant_id')
                ->orWhere('tenant_id', $tenant->id);
        })->get();

        $roles = $roles->reject(fn ($role): bool => $role->isSystemRole() && $role->name === 'Superadmin');

        if (! $this->canManageOrgSuperadmins()) {
            $roles = $roles->reject(fn ($role): bool => $role->isSystemRole() && $role->name === 'Org Superadmin');
        }

        $ceiling = app(PermissionCeiling::class);
        $actor = auth()->user();
        $roles = $roles->filter(fn (Role $role): bool => $actor instanceof User && $ceiling->canGrantRole($actor, $role));

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

    private function canManageOrgSuperadmins(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && ($user->isGlobalSuperAdmin() || $user->hasRole('Org Superadmin'));
    }

    private function wouldRemoveFinalActiveOrgSuperadmin(User $user, int|string $roleId, string $status, string $tenantId): bool
    {
        if (! $this->isFinalActiveOrgSuperadmin($user, $tenantId)) {
            return false;
        }

        $selectedRole = Role::query()->find($roleId);

        return $selectedRole?->name !== 'Org Superadmin' || $status !== User::STATUS_ACTIVE;
    }

    private function isFinalActiveOrgSuperadmin(User $user, string $tenantId): bool
    {
        if (! $this->userHasTenantRole($user, 'Org Superadmin', $tenantId) || $user->status !== User::STATUS_ACTIVE) {
            return false;
        }

        $roleId = Role::whereNull('tenant_id')->where('name', 'Org Superadmin')->value('id');

        // model_has_roles.model_id is varchar while users.id is bigint, so
        // Postgres refuses to join the two columns directly. Collect the ids
        // and cast them instead.
        $ownerIds = DB::connection('landlord')
            ->table(config('permission.table_names.model_has_roles'))
            ->where('role_id', $roleId)
            ->where('model_type', User::class)
            ->where('tenant_id', $tenantId)
            ->pluck('model_id')
            ->map(fn (string $id): int => (int) $id)
            ->all();

        if ($ownerIds === []) {
            return false;
        }

        return User::query()
            ->whereIn('id', $ownerIds)
            ->where('status', User::STATUS_ACTIVE)
            ->count() <= 1;
    }

    private function userHasTenantRole(User $user, string $roleName, string $tenantId): bool
    {
        return DB::connection('landlord')
            ->table(config('permission.table_names.model_has_roles'))
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_id', (string) $user->id)
            ->where('model_has_roles.model_type', User::class)
            ->where('model_has_roles.tenant_id', $tenantId)
            ->where('roles.name', $roleName)
            ->exists();
    }

    /**
     * Whether every role this person holds is one the signed-in user could
     * grant. Anyone stronger than you is not yours to edit or remove.
     */
    private function withinReach(User $user): bool
    {
        $actor = auth()->user();
        if (! $actor instanceof User) {
            return false;
        }

        $ceiling = app(PermissionCeiling::class);

        return $user->roles->every(fn (mixed $role): bool => $role instanceof Role && $ceiling->canGrantRole($actor, $role));
    }
}
