<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Events\SessionAttendanceUpdated;
use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventSession;
use App\Models\EventSessionAttendance;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class EventSessionCheckInController extends Controller
{
    /**
     * Live room headcount and occupancy metrics across all sessions in the event.
     */
    public function occupancy(Request $request, string $subdomain, string $event): JsonResponse
    {
        $this->authorize('read event');
        $tenant = $this->getTenant();
        $eventModel = $this->findEvent($tenant->id, $event);

        $sessions = $eventModel->sessions()
            ->withCount(['registrations'])
            ->orderBy('starts_at')
            ->get()
            ->map(function (EventSession $s): array {
                $headcount = $s->liveHeadcount();
                $capacity = $s->capacity;
                $pct = $s->occupancyPercentage();
                $isFull = $s->isRoomFull();
                $now = now();
                $isLive = $s->starts_at && $s->ends_at && $now->between($s->starts_at, $s->ends_at);

                $status = match (true) {
                    $isFull => 'at_capacity',
                    $pct >= 85 => 'near_capacity',
                    default => 'available',
                };

                return [
                    'id' => $s->id,
                    'title' => $s->title,
                    'location' => $s->location ?: 'Main Hall',
                    'track' => $s->track,
                    'type' => $s->type,
                    'starts_at' => $s->starts_at?->toIso8601String(),
                    'ends_at' => $s->ends_at?->toIso8601String(),
                    'capacity' => $capacity,
                    'registered_count' => $s->registrations_count,
                    'live_headcount' => $headcount,
                    'occupancy_percentage' => $pct,
                    'is_at_capacity' => $isFull,
                    'is_live' => $isLive,
                    'status' => $status,
                ];
            });

        return response()->json([
            'event_id' => $eventModel->id,
            'sessions' => $sessions,
            'summary' => [
                'total_sessions' => $sessions->count(),
                'active_rooms_count' => $sessions->where('live_headcount', '>', 0)->count(),
                'full_rooms_count' => $sessions->where('is_at_capacity', true)->count(),
                'total_in_sessions' => $sessions->sum('live_headcount'),
            ],
        ]);
    }

    /**
     * Multi-point scanner: Scan In or Scan Out an attendee badge for a breakout session.
     */
    public function scan(Request $request, string $subdomain, string $event, string $session): JsonResponse
    {
        $this->authorize('update event');
        $tenant = $this->getTenant();
        $eventModel = $this->findEvent($tenant->id, $event);

        $sessionModel = $eventModel->sessions()
            ->where('id', $session)
            ->firstOrFail();

        $validated = $request->validate([
            'token' => ['nullable', 'string'],
            'registration_id' => ['nullable', 'uuid'],
            'action' => ['nullable', 'in:check_in,check_out'],
            'override_capacity' => ['nullable', 'boolean'],
            'device_name' => ['nullable', 'string', 'max:64'],
        ]);

        $action = $validated['action'] ?? 'check_in';
        $registrationModel = null;

        if (! empty($validated['token'])) {
            $registrationModel = $eventModel->registrations()
                ->where('qr_token', $validated['token'])
                ->first();
        } elseif (! empty($validated['registration_id'])) {
            $registrationModel = $eventModel->registrations()
                ->where('id', $validated['registration_id'])
                ->first();
        }

        if (! $registrationModel) {
            return response()->json(['message' => 'Attendee badge or ticket not recognized.'], 404);
        }

        if (! $registrationModel->isConfirmed()) {
            return response()->json([
                'message' => "{$registrationModel->full_name} is not confirmed for this event.",
            ], 422);
        }

        if ($action === 'check_in') {
            // Room capacity guard
            if ($sessionModel->isRoomFull() && ! ($validated['override_capacity'] ?? false)) {
                return response()->json([
                    'message' => "Room is at maximum capacity ({$sessionModel->capacity} attendees). Entry denied.",
                    'is_room_full' => true,
                    'current_headcount' => $sessionModel->liveHeadcount(),
                    'capacity' => $sessionModel->capacity,
                ], 422);
            }

            // Check if already in the room
            $existing = EventSessionAttendance::where('session_id', $sessionModel->id)
                ->where('registration_id', $registrationModel->id)
                ->whereNull('checked_out_at')
                ->first();

            if ($existing) {
                return response()->json([
                    'message' => "{$registrationModel->full_name} is already checked into {$sessionModel->title}.",
                    'already_checked_in' => true,
                    'live_headcount' => $sessionModel->liveHeadcount(),
                    'attendance' => $existing,
                    'registration' => [
                        'id' => $registrationModel->id,
                        'full_name' => $registrationModel->full_name,
                        'ticket_code' => $registrationModel->ticket_code,
                    ],
                ]);
            }

            $attendance = EventSessionAttendance::create([
                'tenant_id' => $tenant->id,
                'event_id' => $eventModel->id,
                'session_id' => $sessionModel->id,
                'registration_id' => $registrationModel->id,
                'checked_in_at' => now(),
                'checked_in_by' => $request->user()?->id,
                'device_name' => $validated['device_name'] ?? null,
            ]);

            // Broadcast real-time Reverb update
            broadcast(new SessionAttendanceUpdated($sessionModel, 'check_in', $registrationModel->full_name));

            return response()->json([
                'message' => "{$registrationModel->full_name} checked in.",
                'action' => 'check_in',
                'live_headcount' => $sessionModel->liveHeadcount(),
                'capacity' => $sessionModel->capacity,
                'occupancy_percentage' => $sessionModel->occupancyPercentage(),
                'attendance' => $attendance,
                'registration' => [
                    'id' => $registrationModel->id,
                    'full_name' => $registrationModel->full_name,
                    'ticket_code' => $registrationModel->ticket_code,
                    'title' => $registrationModel->title,
                ],
            ]);
        }

        // Action: Check Out
        $activeAttendance = EventSessionAttendance::where('session_id', $sessionModel->id)
            ->where('registration_id', $registrationModel->id)
            ->whereNull('checked_out_at')
            ->latest('checked_in_at')
            ->first();

        if (! $activeAttendance) {
            return response()->json([
                'message' => "{$registrationModel->full_name} is not currently checked into this room.",
                'not_checked_in' => true,
            ], 422);
        }

        $activeAttendance->update([
            'checked_out_at' => now(),
            'checked_out_by' => $request->user()?->id,
        ]);

        // Broadcast real-time Reverb update
        broadcast(new SessionAttendanceUpdated($sessionModel, 'check_out', $registrationModel->full_name));

        return response()->json([
            'message' => "{$registrationModel->full_name} checked out. Dwell time: {$activeAttendance->durationMinutes()} min.",
            'action' => 'check_out',
            'live_headcount' => $sessionModel->liveHeadcount(),
            'capacity' => $sessionModel->capacity,
            'occupancy_percentage' => $sessionModel->occupancyPercentage(),
            'duration_minutes' => $activeAttendance->durationMinutes(),
            'hours_earned' => $activeAttendance->contactHoursEarned(),
            'attendance' => $activeAttendance,
            'registration' => [
                'id' => $registrationModel->id,
                'full_name' => $registrationModel->full_name,
                'ticket_code' => $registrationModel->ticket_code,
            ],
        ]);
    }

    /**
     * Search attendees in a session or view the live room roster.
     */
    public function attendees(Request $request, string $subdomain, string $event, string $session): JsonResponse
    {
        $this->authorize('read event');
        $tenant = $this->getTenant();
        $eventModel = $this->findEvent($tenant->id, $event);

        $sessionModel = $eventModel->sessions()
            ->where('id', $session)
            ->firstOrFail();

        $filter = $request->query('filter', 'active'); // active | all
        $query = (string) $request->query('q', '');

        $attendancesQuery = $sessionModel->attendances()
            ->with(['registration:id,full_name,email,ticket_code,title'])
            ->when($filter === 'active', fn ($q) => $q->whereNull('checked_out_at'))
            ->when(! empty($query), function ($q) use ($query): void {
                $q->whereHas('registration', function ($rq) use ($query): void {
                    $rq->where('full_name', 'like', "%{$query}%")
                        ->orWhere('email', 'like', "%{$query}%")
                        ->orWhere('ticket_code', 'like', "%{$query}%");
                });
            })
            ->latest('checked_in_at');

        $attendances = $attendancesQuery->limit(50)->get()->map(fn (EventSessionAttendance $a): array => [
            'id' => $a->id,
            'registration_id' => $a->registration_id,
            'attendee_name' => $a->registration?->full_name,
            'attendee_email' => $a->registration?->email,
            'ticket_code' => $a->registration?->ticket_code,
            'title' => $a->registration?->title,
            'checked_in_at' => $a->checked_in_at?->toIso8601String(),
            'checked_out_at' => $a->checked_out_at?->toIso8601String(),
            'duration_minutes' => $a->durationMinutes(),
            'contact_hours' => $a->contactHoursEarned(),
            'is_in_room' => $a->isCurrentlyInRoom(),
        ]);

        return response()->json([
            'session' => [
                'id' => $sessionModel->id,
                'title' => $sessionModel->title,
                'location' => $sessionModel->location,
                'capacity' => $sessionModel->capacity,
                'live_headcount' => $sessionModel->liveHeadcount(),
            ],
            'attendees' => $attendances,
        ]);
    }

    private function getTenant()
    {
        $tenant = app(TenantContext::class)->getTenant();
        if (! $tenant) {
            abort(404);
        }

        return $tenant;
    }

    private function findEvent(string $tenantId, string $event): Event
    {
        return Event::where('tenant_id', $tenantId)
            ->where(function ($query) use ($event): void {
                $query->where('id', $event)->orWhere('slug', $event);
            })
            ->firstOrFail();
    }
}
