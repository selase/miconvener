<?php

declare(strict_types=1);

namespace App\Services\Events;

use App\Models\Event;
use App\Models\EventStaffAssignment;
use App\Models\User;

final class EventTeamScope
{
    /**
     * @param  iterable<int|string>  $existingParticipants
     * @return list<int|string>
     */
    public static function eligibleIds(User $actor, Event $event, iterable $existingParticipants = []): array
    {
        $assigned = EventStaffAssignment::query()->where('tenant_id', $event->tenant_id)->where('event_id', $event->id)->pluck('user_id');
        $ids = $assigned->merge(collect($existingParticipants))->push($actor->id)->unique();

        return User::query()->whereIn('id', $ids)
            ->where(fn ($query) => $query->where('tenant_id', $event->tenant_id)->orWhereHas('tenants', fn ($query) => $query->where('tenants.id', $event->tenant_id)))
            ->pluck('id')->all();
    }
}
