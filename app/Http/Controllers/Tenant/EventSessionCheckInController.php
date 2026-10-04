<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventSession;
use App\Models\EventSessionAttendance;
use App\Services\Events\RoomScan;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

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
        abort_unless(\Illuminate\Support\Facades\Gate::any(EventCheckInController::PERMISSIONS), 403);
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

        $result = app(RoomScan::class)->scan(
            $sessionModel,
            $registrationModel,
            $validated['action'] ?? 'check_in',
            (bool) ($validated['override_capacity'] ?? false),
            $request->user(),
            $validated['device_name'] ?? null,
        );

        return response()->json($result['body'], $result['status']);
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
                if (Str::isUuid($event)) {
                    $query->where('id', $event);
                } else {
                    $query->where('slug', $event);
                }
            })
            ->firstOrFail();
    }
}
