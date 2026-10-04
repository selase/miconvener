<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Models\Event;
use App\Models\EventDoorScan;
use App\Models\EventRegistration;
use App\Models\Tenant;
use App\Services\Events\DoorCheckIn;
use Illuminate\Support\Carbon;

/**
 * A multi-day event admits each guest once a day, not once for the whole
 * event, and every scan -- live or synced from a phone that was offline -- is
 * logged so a ticket used twice in one day can be found afterwards.
 */
beforeEach(function (): void {
    refreshTenantDatabases();
    $this->tenant = Tenant::factory()->create(['slug' => 'doordays', 'isolation_mode' => 'shared']);
});

function threeDayEvent(Tenant $tenant, string $timezone = 'Africa/Accra'): Event
{
    return Event::factory()->published()->create([
        'tenant_id' => $tenant->id,
        'timezone' => $timezone,
        'starts_at' => Carbon::parse('2026-11-02 08:00', $timezone),
        'ends_at' => Carbon::parse('2026-11-04 17:00', $timezone),
    ]);
}

function guestOf(Event $event): EventRegistration
{
    return EventRegistration::factory()->create([
        'tenant_id' => $event->tenant_id,
        'event_id' => $event->id,
        'status' => EventRegistration::STATUS_CONFIRMED,
    ]);
}

test('a guest is admitted once per day of a multi-day event', function (): void {
    $event = threeDayEvent($this->tenant);
    $guest = guestOf($event);
    $door = app(DoorCheckIn::class);

    $this->travelTo(Carbon::parse('2026-11-02 09:00', 'Africa/Accra'));
    expect($door->checkIn($guest)['body']['outcome'])->toBe(EventDoorScan::OUTCOME_ADMITTED);
    expect($door->checkIn($guest->fresh())['body']['outcome'])->toBe(EventDoorScan::OUTCOME_ALREADY_IN);

    $this->travelTo(Carbon::parse('2026-11-03 08:30', 'Africa/Accra'));
    $dayTwo = $door->checkIn($guest->fresh());
    expect($dayTwo['body']['outcome'])->toBe(EventDoorScan::OUTCOME_ADMITTED)
        ->and($dayTwo['body']['message'])->toContain('Day 2');

    // First arrival is kept.
    expect($guest->fresh()->checked_in_at->setTimezone('Africa/Accra')->toDateString())->toBe('2026-11-02');
});

test("headcount is today's entries, with check-ins from before the log counted on their day", function (): void {
    $event = threeDayEvent($this->tenant);
    $door = app(DoorCheckIn::class);
    $this->travelTo(Carbon::parse('2026-11-02 10:00', 'Africa/Accra'));
    [$a, $b] = [guestOf($event), guestOf($event)];
    $door->checkIn($a);
    // A check-in made before the scan log existed (no door scan row).
    $b->update(['status' => EventRegistration::STATUS_CHECKED_IN, 'checked_in_at' => now()]);

    expect($door->counts($event))->toMatchArray(['checked_in' => 2, 'expected' => 2, 'day' => 1, 'is_multi_day' => true]);

    $this->travelTo(Carbon::parse('2026-11-03 10:00', 'Africa/Accra'));
    expect($door->counts($event)['checked_in'])->toBe(0);
});

test('the day follows the event timezone, not UTC', function (): void {
    $event = threeDayEvent($this->tenant, 'Pacific/Auckland');
    $guest = guestOf($event);
    $door = app(DoorCheckIn::class);

    // 07:00 UTC on 2 Nov is 20:00 in Auckland; 11:30 UTC is 00:30 on 3 Nov there.
    $this->travelTo(Carbon::parse('2026-11-02 07:00', 'UTC'));
    $door->checkIn($guest);
    $this->travelTo(Carbon::parse('2026-11-02 11:30', 'UTC'));

    expect($door->checkIn($guest->fresh())['body']['outcome'])->toBe(EventDoorScan::OUTCOME_ADMITTED);
});

test('an offline admission synced after another door admitted the guest is a duplicate, and the earlier time wins', function (): void {
    $event = threeDayEvent($this->tenant);
    $guest = guestOf($event);
    $door = app(DoorCheckIn::class);
    $this->travelTo(Carbon::parse('2026-11-02 09:10', 'Africa/Accra'));
    $door->checkIn($guest);

    $result = $door->checkIn($guest->fresh(), scannedAt: Carbon::parse('2026-11-02 09:02', 'Africa/Accra'), clientScanId: 'scan-1', wasOffline: true);

    expect($result['body']['outcome'])->toBe(EventDoorScan::OUTCOME_DUPLICATE)
        ->and($guest->fresh()->checked_in_at->setTimezone('Africa/Accra')->format('H:i'))->toBe('09:02')
        ->and(EventDoorScan::query()->admittedOn('2026-11-02')->where('registration_id', $guest->id)->count())->toBe(2);
});

test('a client scan id is applied only once', function (): void {
    $event = threeDayEvent($this->tenant);
    $guest = guestOf($event);
    $door = app(DoorCheckIn::class);
    $this->travelTo(Carbon::parse('2026-11-02 09:00', 'Africa/Accra'));

    $door->checkIn($guest, scannedAt: now(), clientScanId: 'scan-9', wasOffline: true);
    $again = $door->checkIn($guest->fresh(), scannedAt: now(), clientScanId: 'scan-9', wasOffline: true);

    expect($again['body']['outcome'])->toBe(EventDoorScan::OUTCOME_ADMITTED)
        ->and(EventDoorScan::query()->where('client_scan_id', 'scan-9')->count())->toBe(1);
});

test('a cancelled registration is refused and the refusal is logged', function (): void {
    $event = threeDayEvent($this->tenant);
    $guest = guestOf($event);
    $guest->update(['status' => EventRegistration::STATUS_CANCELLED]);

    $result = app(DoorCheckIn::class)->checkIn($guest->fresh(), clientScanId: 'scan-x', wasOffline: true);

    expect($result['status'])->toBe(422)
        ->and($result['body']['outcome'])->toBe(EventDoorScan::OUTCOME_REFUSED)
        ->and(EventDoorScan::query()->where('client_scan_id', 'scan-x')->value('outcome'))->toBe('refused');
});

test('a single-day event says nothing about days', function (): void {
    $event = Event::factory()->published()->create([
        'tenant_id' => $this->tenant->id,
        'timezone' => 'UTC',
        'starts_at' => now()->startOfDay()->addHours(8),
        'ends_at' => now()->startOfDay()->addHours(17),
    ]);
    $this->travelTo(now()->startOfDay()->addHours(9));

    $message = app(DoorCheckIn::class)->checkIn(guestOf($event))['body']['message'];

    expect($message)->not->toContain('Day');
});
