<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\StoreTeamMemberRequest;
use App\Http\Requests\Tenant\UpdateTeamMemberRequest;
use App\Models\Event;
use App\Models\EventStaffAssignment;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Authorization\PermissionCeiling;
use App\Services\Tenancy\OrganisationInvitations;
use App\Services\Tenancy\OrganisationMemberships;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
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

        $users = $tenant->allUsers()->wherePivotIn('status', ['active', 'suspended'])
            ->with(['roles:id,name', 'roles.permissions:id,name'])
            ->orderByDesc('users.created_at')
            ->paginate(20);

        $assignments = EventStaffAssignment::query()
            ->where('tenant_id', $tenant->id)
            ->whereIn('user_id', $users->getCollection()->pluck('id'))
            ->get(['user_id', 'event_id'])
            ->groupBy('user_id');

        $users = $users->through(fn (User $user): array => [
            'uuid' => $user->uuid,
            'name' => $user->displayName(),
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'email' => $user->email,
            'phone_no' => $user->phone_no,
            'avatar' => $user->photo ? Storage::url($user->photo) : $user->gravatar,
            'role' => $user->roles->pluck('name')->first(),
            'role_id' => $user->roles->first()?->id,
            'status' => $user->pivot->status,
            'last_login_at' => $user->last_login_at?->diffForHumans(),
            'created_at' => $user->created_at->format('Y-m-d'),
            'can_edit' => config('organisation_access.enabled') && ($actorCanManageOwners || ! $user->roles->contains('name', 'Org Superadmin'))
                && $this->withinReach($user),
            'can_remove' => config('organisation_access.enabled') && ($actorCanManageOwners || ! $user->roles->contains('name', 'Org Superadmin'))
                && $this->withinReach($user)
                && $user->id !== auth()->id(),
            // Your own role is never yours to change.
            'can_change_role' => $user->id !== auth()->id(),
            // Empty assignments grant all events to Event Staff and none to Event Organisers.
            'event_ids' => ($assignments->get($user->id) ?? collect())->pluck('event_id')->map(fn (mixed $id): string => (string) $id)->values()->all(),
        ]);

        return Inertia::render('Tenant/Team/Index', [
            'users' => $users,
            'roles' => $this->availableRoles(),
            'statuses' => ['active', 'suspended'],
            'canInvite' => config('organisation_access.enabled') && (bool) auth()->user()?->can('create user'),
            'invitations' => \App\Models\OrganisationInvitation::query()->where('tenant_id', $tenant->id)->where('status', 'pending')->orderByDesc('created_at')->limit(100)->get(['id', 'email', 'expires_at', 'delivery_status'])->map(fn ($invite): array => ['id' => $invite->id, 'email' => $invite->email, 'expires_at' => $invite->expires_at->format('j M Y'), 'delivery_status' => $invite->expires_at->isPast() ? 'expired' : $invite->delivery_status]),
            'events' => Event::where('tenant_id', $tenant->id)
                ->orderByDesc('starts_at')
                ->limit(200)
                ->get(['id', 'name', 'starts_at'])
                ->map(fn (Event $event): array => [
                    'id' => (string) $event->id,
                    'name' => $event->name,
                    'starts_at' => $event->starts_at->format('j M Y'),
                ])
                ->values(),
        ]);
    }

    public function store(StoreTeamMemberRequest $request, string $subdomain): RedirectResponse
    {
        $data = $request->validated();
        app(OrganisationInvitations::class)->invite(
            $this->getTenant(), $request->user(), $data['email'],
            Role::query()->findOrFail($data['role']), $data['event_ids'] ?? []
        );

        return redirect()->route('tenant.users.index', ['subdomain' => $subdomain])
            ->with('success', 'Invitation sent. Access starts after they accept.');
    }

    public function update(UpdateTeamMemberRequest $request, string $subdomain, User $user): RedirectResponse
    {
        $data = $request->validated();
        app(OrganisationMemberships::class)->updateAccess(
            $this->getTenant(), $request->user(), $user,
            Role::query()->findOrFail($data['role']), $data['event_ids'] ?? [], $data['status']
        );

        return redirect()->route('tenant.users.index', ['subdomain' => $subdomain])
            ->with('success', 'Organisation access updated.');
    }

    public function destroy(string $subdomain, User $user): RedirectResponse
    {
        app(OrganisationMemberships::class)->revoke($this->getTenant(), auth()->user(), $user);

        return redirect()->route('tenant.users.index', ['subdomain' => $subdomain])
            ->with('success', 'Access removed from this organisation.');
    }

    /**
     * @return array<int, array{id: int, name: string, event_scoped: bool}>
     */
    private function availableRoles(): array
    {
        $tenant = $this->getTenant();

        $roles = Role::with('permissions')->where(function ($query) use ($tenant): void {
            $query->whereNull('tenant_id')
                ->orWhere('tenant_id', $tenant->id);
        })->get();

        $roles = $roles->filter(fn (Role $role): bool => $role->tenant_id !== null || in_array($role->name, OrganisationMemberships::TENANT_ROLES, true));

        if (! $this->canManageOrgSuperadmins()) {
            $roles = $roles->reject(fn ($role): bool => $role->isSystemRole() && $role->name === 'Org Superadmin');
        }

        $ceiling = app(PermissionCeiling::class);
        $actor = auth()->user();
        $roles = $roles->filter(fn (Role $role): bool => $actor instanceof User && $ceiling->canGrantRole($actor, $role));

        return $roles->map(fn ($role): array => [
            'id' => $role->id,
            'name' => $role->name,
            'event_scoped' => $role->isEventScoped(),
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

        return $user->roles->every(fn (Role $role): bool => $ceiling->canGrantRole($actor, $role));
    }
}
