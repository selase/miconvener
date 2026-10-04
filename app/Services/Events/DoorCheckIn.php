<?php

declare(strict_types=1);

namespace App\Services\Events;

use App\Models\Event;
use App\Models\EventDoorScan;
use App\Models\EventRegistration;
use App\Models\EventStaffLink;
use App\Models\User;
use App\Services\Notifications\EventRuleTriggerService;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

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
     * Admit a guest for the event day of the scan. A multi-day event admits
     * each guest once a day; the registration's own check-in stays their
     * first arrival. Every scan is logged, and a scan sent again by a phone
     * (same client id) is answered from the log without being applied twice.
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    public function checkIn(
        EventRegistration $registration,
        ?User $user = null,
        ?EventStaffLink $staffLink = null,
        ?CarbonInterface $scannedAt = null,
        ?string $clientScanId = null,
        bool $wasOffline = false,
    ): array {
        try {
            // One scan of a guest at a time: two doors scanning the same ticket
            // in the same instant must not both admit it.
            return DB::connection('landlord')->transaction(function () use ($registration, $user, $staffLink, $scannedAt, $clientScanId, $wasOffline): array {
                EventRegistration::query()->whereKey($registration->getKey())->lockForUpdate()->first();
                $registration->refresh();

                return $this->decide($registration, $user, $staffLink, $scannedAt, $clientScanId, $wasOffline);
            });
        } catch (UniqueConstraintViolationException) {
            // The same scan, sent twice at once by a phone: the other request stored it.
            $seen = EventDoorScan::query()->where('event_id', $registration->event_id)->where('client_scan_id', $clientScanId)->firstOrFail();

            return $this->replay($registration, $seen);
        }
    }

    /**
     * Today's headcount: anyone admitted at a door today, plus check-ins from
     * before the door log existed (and self check-ins) that fall on today.
     *
     * @return array{checked_in: int, expected: int, day: int, is_multi_day: bool}
     */
    public function counts(Event $event): array
    {
        $today = $event->doorDayFor(now());

        $scanned = EventDoorScan::query()
            ->where('event_id', $event->id)
            ->admittedOn($today)
            ->distinct()
            ->pluck('registration_id');

        $unlogged = $event->registrations()
            ->where('status', EventRegistration::STATUS_CHECKED_IN)
            ->whereDoesntHave('doorScans', fn ($query) => $query->whereIn('outcome', [EventDoorScan::OUTCOME_ADMITTED, EventDoorScan::OUTCOME_DUPLICATE]));

        // A single-day event's check-ins all belong to its one day, even past midnight.
        if ($event->isMultiDay()) {
            $start = Carbon::parse($today, $event->timezone ?: 'Africa/Accra')->startOfDay()->utc();
            $unlogged->whereBetween('checked_in_at', [$start, $start->copy()->addDay()]);
        }

        $unlogged = $unlogged->pluck('id');

        return [
            'checked_in' => $scanned->merge($unlogged)->unique()->count(),
            'expected' => $event->registrations()
                ->whereIn('status', [EventRegistration::STATUS_CONFIRMED, EventRegistration::STATUS_CHECKED_IN])
                ->count(),
            'day' => $event->dayNumber($today),
            'is_multi_day' => $event->isMultiDay(),
        ];
    }

    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    private function decide(
        EventRegistration $registration,
        ?User $user,
        ?EventStaffLink $staffLink,
        ?CarbonInterface $scannedAt,
        ?string $clientScanId,
        bool $wasOffline,
    ): array {
        if ($clientScanId !== null) {
            $seen = EventDoorScan::query()->where('event_id', $registration->event_id)->where('client_scan_id', $clientScanId)->first();

            if ($seen !== null) {
                return $this->replay($registration, $seen);
            }
        }

        $event = $registration->event;
        $at = $this->plausibleTime($event, $scannedAt);
        $day = $event->doorDayFor($at);

        $log = fn (string $outcome): EventDoorScan => EventDoorScan::query()->create([
            'tenant_id' => $registration->tenant_id,
            'event_id' => $registration->event_id,
            'registration_id' => $registration->id,
            'staff_link_id' => $staffLink?->id,
            'user_id' => $user?->id,
            'client_scan_id' => $clientScanId,
            'event_day' => $day,
            'scanned_at' => $at,
            'was_offline' => $wasOffline,
            'outcome' => $outcome,
        ]);

        if (! $registration->isConfirmed()) {
            $log(EventDoorScan::OUTCOME_REFUSED);

            return ['status' => 422, 'body' => [
                'message' => "{$registration->full_name} ({$registration->ticket_code}): this registration is not confirmed.",
                'outcome' => EventDoorScan::OUTCOME_REFUSED,
                'refused' => true,
            ]];
        }

        $registration->loadMissing('seatAssignment.room:id,name');
        $earlier = $this->admissionOn($registration, $day);

        if ($earlier !== null) {
            // Offline, the guest was already let in; live, they are turned away.
            $outcome = $wasOffline ? EventDoorScan::OUTCOME_DUPLICATE : EventDoorScan::OUTCOME_ALREADY_IN;
            $log($outcome);
            $this->keepEarliestArrival($registration, $at);

            return ['status' => 200, 'body' => [
                'message' => "{$registration->full_name} is already in today ({$earlier['time']}, {$earlier['by']}).",
                'outcome' => $outcome,
                'registration' => $this->payload($registration),
                'already_checked_in' => true,
            ]];
        }

        $log(EventDoorScan::OUTCOME_ADMITTED);

        if ($registration->status !== EventRegistration::STATUS_CHECKED_IN) {
            $registration->update([
                'status' => EventRegistration::STATUS_CHECKED_IN,
                'checked_in_at' => $at,
                'checked_in_by' => $user?->id,
                'checked_in_source' => $staffLink !== null ? 'staff_link' : null,
                'checked_in_by_staff_link_id' => $staffLink?->id,
            ]);

            $this->rules->registrationCheckedIn($registration, (string) $registration->checked_in_at?->getTimestamp());
        } else {
            $this->keepEarliestArrival($registration, $at);
        }

        $prefix = $event->isMultiDay() ? 'Day '.$event->dayNumber($day).' · ' : '';

        return ['status' => 200, 'body' => [
            'message' => "{$prefix}{$registration->full_name} checked in.",
            'outcome' => EventDoorScan::OUTCOME_ADMITTED,
            'registration' => $this->payload($registration),
            'already_checked_in' => false,
        ]];
    }

    /**
     * The admission already recorded for this guest on that day, if any. A
     * check-in made before the door log existed counts on its own day.
     *
     * @return array{time: string, by: string}|null
     */
    private function admissionOn(EventRegistration $registration, string $day): ?array
    {
        $timezone = $registration->event->timezone ?: 'Africa/Accra';

        $scan = EventDoorScan::query()
            ->admittedOn($day)
            ->where('registration_id', $registration->id)
            ->with(['staffLink:id,name', 'user:id,first_name,last_name'])
            ->orderBy('scanned_at')
            ->first();

        if ($scan !== null) {
            return ['time' => $scan->scanned_at->copy()->setTimezone($timezone)->format('H:i'), 'by' => $scan->doorLabel()];
        }

        $arrival = $registration->checked_in_at;

        if ($registration->status === EventRegistration::STATUS_CHECKED_IN
            && $arrival !== null
            && $registration->event->doorDayFor($arrival) === $day
            && ! $registration->doorScans()->whereIn('outcome', [EventDoorScan::OUTCOME_ADMITTED, EventDoorScan::OUTCOME_DUPLICATE])->exists()) {
            return ['time' => $arrival->copy()->setTimezone($timezone)->format('H:i'), 'by' => 'an earlier check-in'];
        }

        return null;
    }

    /**
     * A phone's clock can be wrong. Nothing is admitted in the future, and
     * nothing earlier than half a day before the event opens.
     */
    private function plausibleTime(Event $event, ?CarbonInterface $scannedAt): CarbonInterface
    {
        if ($scannedAt === null || $scannedAt->greaterThan(now())) {
            return now();
        }

        $earliest = $event->starts_at->copy()->subHours(12);

        return $scannedAt->lessThan($earliest) ? $earliest : $scannedAt;
    }

    /**
     * A scan synced late from before the recorded arrival becomes the arrival.
     */
    private function keepEarliestArrival(EventRegistration $registration, CarbonInterface $at): void
    {
        if ($registration->checked_in_at !== null && $at->lessThan($registration->checked_in_at)) {
            $registration->update(['checked_in_at' => $at]);
        }
    }

    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    private function replay(EventRegistration $registration, EventDoorScan $seen): array
    {
        return ['status' => $seen->outcome === EventDoorScan::OUTCOME_REFUSED ? 422 : 200, 'body' => [
            'message' => 'Already received.',
            'outcome' => $seen->outcome,
            'registration' => $this->payload($registration),
        ]];
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
