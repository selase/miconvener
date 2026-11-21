<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

use App\Models\Event;
use App\Models\EventStaffAssignment;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Services\Authorization\PermissionCeiling;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class OrganisationMemberships
{
    public const array TENANT_ROLES = ['Org Superadmin', 'Org Admin', 'Event Staff', 'Event Organiser'];

    public function isActive(User $user, Tenant $tenant): bool
    {
        return User::query()->whereKey($user->id)->where(fn ($query) => $query->where('status', User::STATUS_ACTIVE)->orWhereNull('status'))->exists()
            && TenantMembership::query()->where('tenant_id', $tenant->id)->where('user_id', $user->id)->where('status', 'active')->exists();
    }

    /**
     * @template TResult
     *
     * @param  list<User>  $users
     * @param  Closure(): TResult  $operation
     * @return TResult
     */
    public function inTenant(Tenant $tenant, array $users, Closure $operation): mixed
    {
        $previous = getPermissionsTeamId();
        $context = app(TenantContext::class);
        $previousTenant = $context->getTenant();
        $previousTenantId = $context->activeTenantId();
        try {
            $context->setTenant($tenant);
            setPermissionsTeamId($tenant->id);
            foreach ($users as $user) {
                $user->unsetRelation('roles')->unsetRelation('permissions');
            }

            return $operation();
        } finally {
            $context->clear();
            if ($previousTenant !== null) {
                $context->setTenant($previousTenant);
            }
            $context->setActiveTenantId($previousTenantId);
            setPermissionsTeamId($previous);
            foreach ($users as $user) {
                $user->unsetRelation('roles')->unsetRelation('permissions');
            }
        }
    }

    /** @param list<string> $eventIds */
    public function updateAccess(Tenant $tenant, User $actor, User $subject, Role $role, array $eventIds, string $status): TenantMembership
    {
        return DB::connection('landlord')->transaction(function () use ($tenant, $actor, $subject, $role, $eventIds, $status): TenantMembership {
            Tenant::query()->whereKey($tenant->id)->lockForUpdate()->firstOrFail();

            return $this->inTenant($tenant, [$actor, $subject], function () use ($tenant, $actor, $subject, $role, $eventIds, $status): TenantMembership {
                $this->authorizeActor($tenant, $actor, 'update user');
                $membership = $this->membership($tenant, $subject);
                if ($status !== $membership->status && ! config('organisation_access.enabled')) {
                    throw ValidationException::withMessages(['status' => 'Organisation access changes are temporarily unavailable.']);
                }
                abort_unless(in_array($status, ['active', 'suspended'], true), 422);
                $this->authorizeRole($tenant, $actor, $role);
                $this->authorizeSubject($actor, $subject);
                if ($membership->status === 'revoked') {
                    throw ValidationException::withMessages(['status' => 'Invite this person again to restore access.']);
                }
                if ($actor->is($subject) && ($status !== 'active' || ! $subject->hasRole($role->name))) {
                    throw ValidationException::withMessages(['role' => 'Ask another administrator to change your access.']);
                }
                $this->protectOwner($tenant, $subject, $status === 'active' && $role->name === 'Org Superadmin');
                if (Event::query()->where('tenant_id', $tenant->id)->whereIn('id', $eventIds)->count() !== count(array_unique($eventIds))) {
                    throw ValidationException::withMessages(['event_ids' => 'Choose events from this organisation.']);
                }
                $subject->syncRoles([$role]);
                EventStaffAssignment::query()->where('tenant_id', $tenant->id)->where('user_id', $subject->id)->delete();
                foreach ($role->isEventScoped() ? array_unique($eventIds) : [] as $eventId) {
                    EventStaffAssignment::query()->create(['tenant_id' => $tenant->id, 'user_id' => $subject->id, 'event_id' => $eventId]);
                }
                $previousStatus = $membership->status;
                $membership->forceFill(['status' => $status, 'suspended_at' => $status === 'suspended' ? now() : null, 'changed_by' => $actor->id])->save();
                $this->audit($tenant, $actor, $subject, 'updated', ['from_status' => $previousStatus, 'status' => $status, 'role_id' => $role->id]);

                return $membership;
            });
        });
    }

    public function revoke(Tenant $tenant, User $actor, User $subject): void
    {
        if (! config('organisation_access.enabled')) {
            throw ValidationException::withMessages(['user' => 'Organisation access changes are temporarily unavailable.']);
        }
        DB::connection('landlord')->transaction(function () use ($tenant, $actor, $subject): void {
            Tenant::query()->whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $this->inTenant($tenant, [$actor, $subject], function () use ($tenant, $actor, $subject): void {
                $this->authorizeActor($tenant, $actor, 'delete user');
                $membership = $this->membership($tenant, $subject);
                if ($membership->status === 'revoked') {
                    return;
                }
                $this->protectOwner($tenant, $subject, false);
                $this->authorizeSubject($actor, $subject);
                if ($actor->is($subject)) {
                    throw ValidationException::withMessages(['user' => 'Ask another administrator to remove your access.']);
                }
                $subject->syncRoles([]);
                $subject->syncPermissions([]);
                EventStaffAssignment::query()->where('tenant_id', $tenant->id)->where('user_id', $subject->id)->delete();
                $membership->forceFill(['status' => 'revoked', 'revoked_at' => now(), 'changed_by' => $actor->id])->save();
                $this->audit($tenant, $actor, $subject, 'revoked');
            });
        });
    }

    /**
     * Admission is called only by verified invitation acceptance under its tenant reservation lock.
     *
     * @param  list<string>  $eventIds
     */
    public function admit(Tenant $tenant, User $inviter, User $subject, Role $role, array $eventIds): TenantMembership
    {
        abort_unless(config('organisation_access.enabled'), 503, 'Organisation invitations are temporarily unavailable.');

        return DB::connection('landlord')->transaction(function () use ($tenant, $inviter, $subject, $role, $eventIds): TenantMembership {
            Tenant::query()->whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $subject = User::query()->whereKey($subject->id)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($subject->status, [null, User::STATUS_ACTIVE], true), 403);

            return $this->inTenant($tenant, [$inviter, $subject], function () use ($tenant, $inviter, $subject, $role, $eventIds): TenantMembership {
                $this->authorizeActor($tenant, $inviter, 'create user');
                $this->authorizeRole($tenant, $inviter, $role);
                $membership = TenantMembership::query()->where('tenant_id', $tenant->id)->where('user_id', $subject->id)->lockForUpdate()->first();
                if ($membership !== null && $membership->status !== 'revoked') {
                    return $membership;
                }
                if (Event::query()->where('tenant_id', $tenant->id)->whereIn('id', $eventIds)->count() !== count(array_unique($eventIds))) {
                    throw ValidationException::withMessages(['event_ids' => 'Choose events from this organisation.']);
                }
                $membership ??= new TenantMembership(['tenant_id' => $tenant->id, 'user_id' => $subject->id]);
                $membership->forceFill(['status' => 'active', 'accepted_at' => now(), 'revoked_at' => null, 'suspended_at' => null, 'changed_by' => $inviter->id])->save();
                $subject->syncRoles([$role]);
                $subject->syncPermissions([]);
                EventStaffAssignment::query()->where('tenant_id', $tenant->id)->where('user_id', $subject->id)->delete();
                foreach ($role->isEventScoped() ? array_unique($eventIds) : [] as $eventId) {
                    EventStaffAssignment::query()->create(['tenant_id' => $tenant->id, 'user_id' => $subject->id, 'event_id' => $eventId]);
                }

                $this->audit($tenant, $inviter, $subject, 'accepted', ['role_id' => $role->id]);

                return $membership;
            });
        });
    }

    public function authorizeRole(Tenant $tenant, User $actor, Role $role): void
    {
        abort_unless(($role->tenant_id === null && in_array($role->name, self::TENANT_ROLES, true)) || (string) $role->tenant_id === (string) $tenant->id, 403);
        abort_unless(app(PermissionCeiling::class)->canGrantRole($actor, $role), 403);
        abort_if($role->name === 'Org Superadmin' && ! $actor->isGlobalSuperAdmin() && ! $actor->hasRole('Org Superadmin'), 403);
    }

    /** @param array<string, mixed> $properties */
    private function audit(Tenant $tenant, User $actor, User $subject, string $action, array $properties = []): void
    {
        activity('organisation-membership')->performedOn($tenant)->causedBy($actor)
            ->withProperties(['tenant_id' => $tenant->id, 'user_id' => $subject->id, ...$properties])
            ->log('membership.'.$action);
    }

    private function authorizeActor(Tenant $tenant, User $actor, string $ability): void
    {
        $current = User::query()->whereKey($actor->id)->first();
        abort_unless($current && in_array($current->status, [null, User::STATUS_ACTIVE], true), 403);
        abort_unless($actor->isGlobalSuperAdmin() || ($this->isActive($actor, $tenant) && $actor->can($ability)), 403);
    }

    private function authorizeSubject(User $actor, User $subject): void
    {
        abort_unless(app(PermissionCeiling::class)->beyondReach($actor, $subject->getAllPermissions()->pluck('name')) === [], 403);
        abort_unless($subject->roles->every(fn (Role $role): bool => app(PermissionCeiling::class)->canGrantRole($actor, $role)), 403);
        abort_if($subject->hasRole('Org Superadmin') && ! $actor->isGlobalSuperAdmin() && ! $actor->hasRole('Org Superadmin'), 403);
    }

    private function membership(Tenant $tenant, User $user): TenantMembership
    {
        return TenantMembership::query()->where('tenant_id', $tenant->id)->where('user_id', $user->id)->lockForUpdate()->firstOrFail();
    }

    private function protectOwner(Tenant $tenant, User $subject, bool $remainsOwner): void
    {
        if ($remainsOwner || ! $subject->hasRole('Org Superadmin')) {
            return;
        }
        $ownerIds = User::query()->with('roles')->whereHas('tenants', fn ($query) => $query->where('tenants.id', $tenant->id))
            ->where(fn ($query) => $query->where('status', 'active')->orWhereNull('status'))->get()
            ->filter(fn (User $user): bool => $user->hasRole('Org Superadmin'))->pluck('id');
        if ($ownerIds->contains($subject->id) && $ownerIds->count() <= 1) {
            throw ValidationException::withMessages(['role' => 'Assign another active Org Superadmin before changing the final owner.']);
        }
    }
}
