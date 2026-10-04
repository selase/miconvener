<?php

declare(strict_types=1);

namespace App\Services\Events;

use App\Models\Event;
use App\Models\EventServiceRequest;
use App\Models\EventStaffLink;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * The attendee help desk: requests for water, a technician, a wheelchair or a
 * medic, answered by a team member in the console or a crew member on a staff
 * link. One set of rules for both.
 */
final class ServiceRequestDesk
{
    /**
     * Enough of the requester and their seat that whoever answers can walk
     * straight to them.
     *
     * @var list<string>
     */
    public const array RELATIONS = [
        'registration:id,full_name',
        'registration.seatAssignment:id,registration_id,room_id,seat_label',
        'registration.seatAssignment.room:id,name',
        'assignedTo:id,first_name,last_name',
        'assignedStaffLink:id,name',
    ];

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function forEvent(Event $event): Collection
    {
        return $event->serviceRequests()
            ->with(self::RELATIONS)
            ->get()
            ->map(fn (EventServiceRequest $request): array => $request->consolePayload());
    }

    /**
     * @return array<string, mixed>
     */
    public function claim(EventServiceRequest $request, ?User $user = null, ?EventStaffLink $staffLink = null): array
    {
        $request->update([
            'assigned_to' => $user?->id,
            'assigned_staff_link_id' => $staffLink?->id,
            'status' => $request->status === EventServiceRequest::STATUS_OPEN
                ? EventServiceRequest::STATUS_ACKNOWLEDGED
                : $request->status,
            'acknowledged_at' => $request->acknowledged_at ?? now(),
        ]);

        return $request->fresh(self::RELATIONS)->consolePayload();
    }

    /**
     * @return array<string, mixed>
     */
    public function setStatus(EventServiceRequest $request, string $status): array
    {
        $request->update([
            'status' => $status,
            'resolved_at' => $status === EventServiceRequest::STATUS_RESOLVED ? now() : $request->resolved_at,
        ]);

        return $request->fresh(self::RELATIONS)->consolePayload();
    }
}
