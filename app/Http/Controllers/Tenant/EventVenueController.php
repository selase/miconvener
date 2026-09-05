<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventSeatAssignment;
use App\Models\EventVenueRoom;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class EventVenueController extends Controller
{
    public function storeRoom(Request $request, string $subdomain, string $event): JsonResponse
    {
        $this->authorize('update event');
        $tenant = $this->getTenant();
        $eventModel = $this->findEvent($tenant->id, $event);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'rows' => ['required', 'integer', 'min:1', 'max:26'],
            'seats_per_row' => ['required', 'integer', 'min:1', 'max:60'],
        ]);

        $room = $eventModel->venueRooms()->create([...$validated, 'tenant_id' => $tenant->id]);

        return response()->json($this->roomPayload($room));
    }

    public function destroyRoom(string $subdomain, string $event, string $room): JsonResponse
    {
        $this->authorize('update event');
        $tenant = $this->getTenant();
        $eventModel = $this->findEvent($tenant->id, $event);

        $eventModel->venueRooms()->where('id', $room)->firstOrFail()->delete();

        return response()->json(['message' => 'Room deleted.']);
    }

    public function assignSeat(Request $request, string $subdomain, string $event, string $room): JsonResponse
    {
        $this->authorize('update event');
        $tenant = $this->getTenant();
        $eventModel = $this->findEvent($tenant->id, $event);
        $roomModel = $eventModel->venueRooms()->where('id', $room)->firstOrFail();

        $validated = $request->validate([
            'seat_label' => ['required', 'string', Rule::in($roomModel->seatLabels())],
            'registration_id' => [
                'required',
                Rule::exists('event_registrations', 'id')->where('event_id', $eventModel->id),
            ],
        ]);

        $takenByOther = EventSeatAssignment::where('room_id', $roomModel->id)
            ->where('seat_label', $validated['seat_label'])
            ->where('registration_id', '!=', $validated['registration_id'])
            ->exists();

        if ($takenByOther) {
            return response()->json(['message' => 'That seat is already assigned to someone else.'], 422);
        }

        EventSeatAssignment::updateOrCreate(
            ['registration_id' => $validated['registration_id']],
            [
                'tenant_id' => $tenant->id,
                'event_id' => $eventModel->id,
                'room_id' => $roomModel->id,
                'seat_label' => $validated['seat_label'],
            ]
        );

        return response()->json(['message' => 'Seat assigned.']);
    }

    public function unassignSeat(string $subdomain, string $event, string $room, string $assignment): JsonResponse
    {
        $this->authorize('update event');
        $tenant = $this->getTenant();
        $eventModel = $this->findEvent($tenant->id, $event);

        EventSeatAssignment::where('tenant_id', $tenant->id)
            ->where('event_id', $eventModel->id)
            ->where('room_id', $room)
            ->where('id', $assignment)
            ->firstOrFail()
            ->delete();

        return response()->json(['message' => 'Seat unassigned.']);
    }

    public function searchUnseated(Request $request, string $subdomain, string $event): JsonResponse
    {
        $this->authorize('update event');
        $tenant = $this->getTenant();
        $eventModel = $this->findEvent($tenant->id, $event);

        $query = (string) $request->query('q', '');
        $assignedIds = EventSeatAssignment::where('event_id', $eventModel->id)->pluck('registration_id');

        $registrations = $eventModel->registrations()
            ->confirmed()
            ->whereNotIn('id', $assignedIds)
            ->where(function ($q) use ($query): void {
                $q->where('full_name', 'like', "%{$query}%")->orWhere('email', 'like', "%{$query}%");
            })
            ->limit(10)
            ->get(['id', 'full_name', 'email']);

        return response()->json($registrations);
    }

    private function findEvent(string $tenantId, string $eventId): Event
    {
        return Event::where('tenant_id', $tenantId)->where('id', $eventId)->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    private function roomPayload(EventVenueRoom $room): array
    {
        return [
            'id' => $room->id,
            'name' => $room->name,
            'rows' => $room->rows,
            'seats_per_row' => $room->seats_per_row,
            'capacity' => $room->capacity(),
        ];
    }

    private function getTenant()
    {
        $tenant = app(TenantContext::class)->getTenant();
        if (! $tenant) {
            abort(403, 'Tenant context not resolved.');
        }

        return $tenant;
    }
}
