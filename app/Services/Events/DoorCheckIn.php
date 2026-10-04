<?php

declare(strict_types=1);

namespace App\Services\Events;

use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\EventStaffLink;
use App\Models\User;
use App\Services\Notifications\EventRuleTriggerService;
use Illuminate\Database\Eloquent\Collection;

/**
 * Checking someone in at the door, whoever is holding the phone: a team member
 * in the console or a crew member on a staff link. One set of rules, so the
 * two can never disagree about who gets in.
 */
final class DoorCheckIn
{
    public function __construct(private readonly EventRuleTriggerService $rules) {}

    /**
     * @return Collection<int, EventRegistration>
     */
    public function search(Event $event, string $query): Collection
    {
        return $event->registrations()
            ->confirmed()
            ->where(function ($q) use ($query): void {
                $q->where('full_name', 'ilike', "%{$query}%")
                    ->orWhere('email', 'ilike', "%{$query}%")
                    ->orWhere('ticket_code', 'ilike', "%{$query}%");
            })
            ->limit(10)
            ->get(['id', 'full_name', 'email', 'ticket_code', 'status', 'checked_in_at']);
    }

    public function findByQrToken(Event $event, string $token): ?EventRegistration
    {
        return $event->registrations()->where('qr_token', $token)->first();
    }

    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    public function checkIn(EventRegistration $registration, ?User $user = null, ?EventStaffLink $staffLink = null): array
    {
        if (! $registration->isConfirmed()) {
            return ['status' => 422, 'body' => ['message' => 'This registration is not confirmed.']];
        }

        $registration->loadMissing('seatAssignment.room:id,name');

        if ($registration->status === EventRegistration::STATUS_CHECKED_IN) {
            return ['status' => 200, 'body' => [
                'message' => "{$registration->full_name} was already checked in.",
                'registration' => $this->payload($registration),
                'already_checked_in' => true,
            ]];
        }

        $registration->update([
            'status' => EventRegistration::STATUS_CHECKED_IN,
            'checked_in_at' => now(),
            'checked_in_by' => $user?->id,
            'checked_in_source' => $staffLink !== null ? 'staff_link' : null,
            'checked_in_by_staff_link_id' => $staffLink?->id,
        ]);

        $this->rules->registrationCheckedIn($registration, (string) $registration->checked_in_at?->getTimestamp());

        return ['status' => 200, 'body' => [
            'message' => "{$registration->full_name} checked in.",
            'registration' => $this->payload($registration),
            'already_checked_in' => false,
        ]];
    }

    /**
     * @return array{checked_in: int, expected: int}
     */
    public function counts(Event $event): array
    {
        return [
            'checked_in' => $event->registrations()->where('status', EventRegistration::STATUS_CHECKED_IN)->count(),
            'expected' => $event->registrations()
                ->whereIn('status', [EventRegistration::STATUS_CONFIRMED, EventRegistration::STATUS_CHECKED_IN])
                ->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(EventRegistration $registration): array
    {
        return [
            ...$registration->only(['id', 'full_name', 'ticket_code', 'checked_in_at']),
            'seat_label' => $registration->seatAssignment?->seat_label,
            'room_name' => $registration->seatAssignment?->room?->name,
        ];
    }
}
