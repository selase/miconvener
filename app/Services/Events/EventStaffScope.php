<?php

declare(strict_types=1);

namespace App\Services\Events;

use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\EventStaffAssignment;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Which of an organisation's events a team member may reach.
 *
 * Event Organisers always require explicit assignments. Event Staff retain
 * their legacy all-events access until assignments are chosen. Organisation
 * administrators remain unrestricted.
 */
final class EventStaffScope
{
    public const string ROLE = 'Event Staff';

    public const string ORGANISER_ROLE = 'Event Organiser';

    public static function isEventOrganiser(User $user, string $tenantId): bool
    {
        $roles = self::systemRoles($user, $tenantId);

        return in_array(self::ORGANISER_ROLE, $roles, true)
            && array_intersect(['Superadmin', 'Org Superadmin', 'Org Admin'], $roles) === []
            && ! $user->isGlobalSuperAdmin();
    }

    public static function canCancelRegistration(User $user, EventRegistration $registration): bool
    {
        if (! self::isEventOrganiser($user, (string) $registration->tenant_id)) {
            return true;
        }

        return $registration->isImported()
            || (! $registration->member_benefit_required && (int) $registration->amount === 0
                && $registration->payment_reference === null);
    }

    /**
     * The event ids the member is limited to, or null when not limited.
     *
     * @return list<string>|null
     */
    public static function restrictedEventIds(User $user, string $tenantId): ?array
    {
        $organiser = self::isEventOrganiser($user, $tenantId);
        if (! $organiser && ! self::holdsOnlyEventStaff($user, $tenantId)) {
            return null;
        }

        if ($organiser && (! User::withoutGlobalScopes()->whereKey($user->id)->where(fn ($query) => $query->where('status', User::STATUS_ACTIVE)->orWhereNull('status'))->exists() || ! $user->tenants()->whereKey($tenantId)->exists())) {
            return [];
        }

        $ids = EventStaffAssignment::query()
            ->where('tenant_id', $tenantId)
            ->where('user_id', $user->id)
            ->pluck('event_id')
            ->map(fn (mixed $id): string => (string) $id)
            ->values()
            ->all();

        return ! $organiser && $ids === [] ? null : $ids;
    }

    public static function canListenToEvent(User $user, Event $event): bool
    {
        $actor = User::withoutGlobalScopes()->find($user->id);
        if ($actor === null || ($actor->status !== null && $actor->status !== User::STATUS_ACTIVE)
            || (! $actor->isGlobalSuperAdmin() && ! $actor->tenants()->whereKey($event->tenant_id)->exists())) {
            return false;
        }

        $previousTeam = getPermissionsTeamId();
        try {
            setPermissionsTeamId($event->tenant_id);

            return $actor->canAny(['read event', 'update event', 'check in attendees', 'handle service-requests'])
                && self::canAccessEvent($actor, $event);
        } finally {
            setPermissionsTeamId($previousTeam);
        }
    }

    public static function canAccessEvent(User $user, Event $event): bool
    {
        $ids = self::restrictedEventIds($user, (string) $event->tenant_id);

        return $ids === null || in_array((string) $event->id, $ids, true);
    }

    /**
     * Read straight from model_has_roles for this tenant, so the answer does
     * not depend on which team the permission registrar happens to be set to.
     */
    private static function holdsOnlyEventStaff(User $user, string $tenantId): bool
    {
        $roles = DB::connection('landlord')->table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_type', User::class)
            ->where('model_has_roles.model_id', (string) $user->id)
            ->where('model_has_roles.tenant_id', $tenantId)
            ->pluck('roles.name')
            ->unique()
            ->values()
            ->all();

        return $roles === [self::ROLE];
    }

    /** @return list<string> */
    private static function systemRoles(User $user, string $tenantId): array
    {
        return DB::connection('landlord')->table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_type', User::class)
            ->where('model_has_roles.model_id', (string) $user->id)
            ->where('model_has_roles.tenant_id', $tenantId)
            ->whereNull('roles.tenant_id')
            ->where('roles.guard_name', 'web')
            ->pluck('roles.name')->unique()->values()->all();
    }
}
