<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\EventSession;
use App\Models\EventSessionAttendance;
use App\Models\EventStaffLink;
use App\Models\Tenant;

/**
 * An usher on a staff link can scan guests into and out of any room of the
 * event, with the same rules as the console: capacity, no double entry, and
 * the room's live headcount. No assignment of ushers to rooms.
 */
beforeEach(function (): void {
    refreshTenantDatabases();
    $this->tenant = Tenant::factory()->create(['slug' => 'roomcrew', 'isolation_mode' => 'shared']);
    $this->host = 'roomcrew.'.mb_ltrim((string) config('session.domain'), '.');
    $this->event = Event::factory()->published()->create([
        'tenant_id' => $this->tenant->id,
        'starts_at' => now()->subHour(),
        'ends_at' => now()->addHours(6),
    ]);
    $this->room = EventSession::factory()->create([
        'tenant_id' => $this->tenant->id,
        'event_id' => $this->event->id,
        'title' => 'Workshop B',
        'capacity' => 1,
    ]);
    $this->link = EventStaffLink::factory()->create(['tenant_id' => $this->tenant->id, 'event_id' => $this->event->id, 'name' => 'Floor – Efua']);
    $this->base = "http://{$this->host}/staff/{$this->link->token}";
    $this->guest = EventRegistration::factory()->create([
        'tenant_id' => $this->tenant->id, 'event_id' => $this->event->id, 'status' => 'confirmed', 'full_name' => 'Ama Owusu',
    ]);
});

function roomScan(object $test, array $body)
{
    return $test->postJson("{$test->base}/rooms/{$test->room->id}/scan", $body, ['HTTP_HOST' => $test->host]);
}

test('an usher scans a guest into a room, and the entry names the link', function (): void {
    roomScan($this, ['token' => $this->guest->qr_token])
        ->assertOk()
        ->assertJsonPath('action', 'check_in')
        ->assertJsonPath('live_headcount', 1);

    $attendance = EventSessionAttendance::query()->where('session_id', $this->room->id)->firstOrFail();
    expect($attendance->registration_id)->toBe($this->guest->id)
        ->and($attendance->device_name)->toBe('Floor – Efua');
});

test('a second scan into the same room says they are already in', function (): void {
    roomScan($this, ['token' => $this->guest->qr_token]);

    roomScan($this, ['registration_id' => $this->guest->id])->assertOk()->assertJsonPath('already_checked_in', true);
});

test('a full room turns the next guest away unless overridden', function (): void {
    roomScan($this, ['token' => $this->guest->qr_token]);
    $second = EventRegistration::factory()->create(['tenant_id' => $this->tenant->id, 'event_id' => $this->event->id, 'status' => 'confirmed']);

    roomScan($this, ['token' => $second->qr_token])->assertUnprocessable()->assertJsonPath('is_room_full', true);
    roomScan($this, ['token' => $second->qr_token, 'override_capacity' => true])->assertOk();
});

test('scanning out records the time spent in the room', function (): void {
    roomScan($this, ['token' => $this->guest->qr_token]);

    roomScan($this, ['token' => $this->guest->qr_token, 'action' => 'check_out'])
        ->assertOk()
        ->assertJsonPath('action', 'check_out')
        ->assertJsonPath('live_headcount', 0);
});

test('a room from another event cannot be scanned into', function (): void {
    $otherEvent = Event::factory()->published()->create(['tenant_id' => $this->tenant->id]);
    $otherRoom = EventSession::factory()->create(['tenant_id' => $this->tenant->id, 'event_id' => $otherEvent->id]);

    $this->postJson("{$this->base}/rooms/{$otherRoom->id}/scan", ['token' => $this->guest->qr_token], ['HTTP_HOST' => $this->host])
        ->assertNotFound();
});

test('a requests-only link cannot scan into rooms', function (): void {
    $this->link->update(['can_check_in' => false, 'can_handle_requests' => true]);

    roomScan($this, ['token' => $this->guest->qr_token])->assertForbidden();
});

test("the staff page lists the event's rooms", function (): void {
    $this->get($this->base, ['HTTP_HOST' => $this->host])
        ->assertInertia(fn ($page) => $page->where('event.sessions.0.id', $this->room->id)->where('event.sessions.0.title', 'Workshop B'));
});
