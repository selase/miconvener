<?php

declare(strict_types=1);

namespace App\Services\Events;

use App\Models\Event;
use App\Models\EventStaffAssignment;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Which of an organisation's events a team member may reach.
 *
 * Only a member whose role is Event Staff can be limited, and only once the
 * organiser has chosen events for them: with none chosen they keep access to
 * every event, as Event Staff always had. Organisers and admins are never
 * limited, whatever assignments exist.
 */
final class EventStaffScope
{
    public const string ROLE = 'Event Staff';

    /**
     * The event ids the member is limited to, or null when not limited.
     *
     * @return list<string>|null
     */
    public static function restrictedEventIds(User $user, string $tenantId): ?array
    {
        if (! self::holdsOnlyEventStaff($user, $tenantId)) {
            return null;
        }

        $ids = EventStaffAssignment::query()
            ->where('tenant_id', $tenantId)
            ->where('user_id', $user->id)
            ->pluck('event_id')
            ->map(fn (mixed $id): string => (string) $id)
            ->values()
            ->all();

        return $ids === [] ? null : $ids;
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
}
