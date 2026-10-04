<?php

declare(strict_types=1);

namespace App\Services\Events;

use App\Events\SessionAttendanceUpdated;
use App\Models\EventRegistration;
use App\Models\EventSession;
use App\Models\EventSessionAttendance;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Scanning a guest into or out of a room, whoever holds the phone: a team
 * member in the console or an usher on a staff link. One set of rules --
 * capacity, no double entry, time spent -- for both.
 */
final class RoomScan
{
    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    public function scan(
        EventSession $session,
        EventRegistration $registration,
        string $action = 'check_in',
        bool $overrideCapacity = false,
        ?User $user = null,
        ?string $deviceName = null,
    ): array {
        if (! $registration->isConfirmed()) {
            return ['status' => 422, 'body' => ['message' => "{$registration->full_name} is not confirmed for this event.", 'refused' => true]];
        }

        return $action === 'check_out'
            ? $this->out($session, $registration, $user)
            : $this->in($session, $registration, $overrideCapacity, $user, $deviceName);
    }

    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    private function in(EventSession $session, EventRegistration $registration, bool $overrideCapacity, ?User $user, ?string $deviceName): array
    {
        $existing = EventSessionAttendance::query()
            ->where('session_id', $session->id)
            ->where('registration_id', $registration->id)
            ->whereNull('checked_out_at')
            ->first();

        if ($existing) {
            return ['status' => 200, 'body' => [
                'message' => "{$registration->full_name} is already checked into {$session->title}.",
                'already_checked_in' => true,
                'live_headcount' => $session->liveHeadcount(),
                'attendance' => $existing,
                'registration' => $this->guest($registration),
            ]];
        }

        if ($session->isRoomFull() && ! $overrideCapacity) {
            return ['status' => 422, 'body' => [
                'message' => "Room is at maximum capacity ({$session->capacity} attendees). Entry denied.",
                'is_room_full' => true,
                'current_headcount' => $session->liveHeadcount(),
                'capacity' => $session->capacity,
            ]];
        }

        $attendance = EventSessionAttendance::query()->create([
            'tenant_id' => $session->tenant_id,
            'event_id' => $session->event_id,
            'session_id' => $session->id,
            'registration_id' => $registration->id,
            'checked_in_at' => now(),
            'checked_in_by' => $user?->id,
            'device_name' => $deviceName,
        ]);

        $this->announce($session, 'check_in', $registration);

        return ['status' => 200, 'body' => [
            'message' => "{$registration->full_name} checked in.",
            'action' => 'check_in',
            'live_headcount' => $session->liveHeadcount(),
            'capacity' => $session->capacity,
            'occupancy_percentage' => $session->occupancyPercentage(),
            'attendance' => $attendance,
            'registration' => [...$this->guest($registration), 'title' => $registration->title],
        ]];
    }

    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    private function out(EventSession $session, EventRegistration $registration, ?User $user): array
    {
        $active = EventSessionAttendance::query()
            ->where('session_id', $session->id)
            ->where('registration_id', $registration->id)
            ->whereNull('checked_out_at')
            ->latest('checked_in_at')
            ->first();

        if (! $active) {
            return ['status' => 422, 'body' => [
                'message' => "{$registration->full_name} is not currently checked into this room.",
                'not_checked_in' => true,
            ]];
        }

        $active->update([
            'checked_out_at' => now(),
            'checked_out_by' => $user?->id,
        ]);

        $this->announce($session, 'check_out', $registration);

        return ['status' => 200, 'body' => [
            'message' => "{$registration->full_name} checked out. Dwell time: {$active->durationMinutes()} min.",
            'action' => 'check_out',
            'live_headcount' => $session->liveHeadcount(),
            'capacity' => $session->capacity,
            'occupancy_percentage' => $session->occupancyPercentage(),
            'duration_minutes' => $active->durationMinutes(),
            'hours_earned' => $active->contactHoursEarned(),
            'attendance' => $active,
            'registration' => $this->guest($registration),
        ]];
    }

    /**
     * The live room screens update on the broadcast; a scan already recorded
     * must not fail because the broadcast server is unreachable.
     */
    private function announce(EventSession $session, string $action, EventRegistration $registration): void
    {
        try {
            broadcast(new SessionAttendanceUpdated($session, $action, $registration->full_name));
        } catch (Throwable $exception) {
            Log::warning('Room headcount broadcast failed', ['session_id' => $session->id, 'error' => $exception->getMessage()]);
        }
    }

    /**
     * @return array{id: string, full_name: string, ticket_code: string|null}
     */
    private function guest(EventRegistration $registration): array
    {
        return [
            'id' => $registration->id,
            'full_name' => $registration->full_name,
            'ticket_code' => $registration->ticket_code,
        ];
    }
}
