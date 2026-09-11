<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Events\SessionAttendanceUpdated;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\EventSession;
use App\Models\EventSessionAttendance;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event as EventFacade;

beforeEach(function () {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
});

test('session occupancy endpoint returns real-time headcounts and room statuses', function () {
    [$tenant, $user] = eventHost('acme');
    $host = eventSubdomainHost('acme');

    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);

    $sessionA = EventSession::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'title' => 'Keynote Hall',
        'location' => 'Main Auditorium',
        'capacity' => 100,
    ]);

    $sessionB = EventSession::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'title' => 'Small Workshop',
        'location' => 'Room 101',
        'capacity' => 2,
    ]);

    $reg1 = EventRegistration::factory()->confirmed()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id]);
    $reg2 = EventRegistration::factory()->confirmed()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id]);

    // Check in 2 attendees to session B (filling capacity of 2)
    EventSessionAttendance::create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'session_id' => $sessionB->id,
        'registration_id' => $reg1->id,
        'checked_in_at' => now(),
    ]);

    EventSessionAttendance::create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'session_id' => $sessionB->id,
        'registration_id' => $reg2->id,
        'checked_in_at' => now(),
    ]);

    $response = $this->actingAs($user)
        ->getJson("http://{$host}/events/{$event->id}/sessions/occupancy", ['HTTP_HOST' => $host]);

    $response->assertOk()
        ->assertJsonPath('summary.total_sessions', 2)
        ->assertJsonPath('summary.active_rooms_count', 1)
        ->assertJsonPath('summary.full_rooms_count', 1)
        ->assertJsonPath('summary.total_in_sessions', 2);

    $sessions = collect($response->json('sessions'));
    $bData = $sessions->firstWhere('id', $sessionB->id);
    expect($bData['live_headcount'])->toBe(2)
        ->and($bData['occupancy_percentage'])->toBe(100)
        ->and($bData['is_at_capacity'])->toBeTrue()
        ->and($bData['status'])->toBe('at_capacity');
});

test('attendee can be checked in to a breakout session and dispatches broadcast event', function () {
    EventFacade::fake([SessionAttendanceUpdated::class]);

    [$tenant, $user] = eventHost('acme');
    $host = eventSubdomainHost('acme');

    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);
    $session = EventSession::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'title' => 'AI Ethics Workshop',
        'capacity' => 50,
    ]);

    $reg = EventRegistration::factory()->confirmed()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'full_name' => 'Dr. Kofi Annan',
        'qr_token' => 'signed_token_123',
    ]);

    $response = $this->actingAs($user)
        ->postJson("http://{$host}/events/{$event->id}/sessions/{$session->id}/scan", [
            'token' => 'signed_token_123',
            'action' => 'check_in',
        ], ['HTTP_HOST' => $host]);

    $response->assertOk()
        ->assertJson([
            'action' => 'check_in',
            'live_headcount' => 1,
        ]);

    expect(EventSessionAttendance::where('session_id', $session->id)->count())->toBe(1);

    EventFacade::assertDispatched(SessionAttendanceUpdated::class, function ($e) use ($session) {
        return $e->session->id === $session->id && $e->action === 'check_in' && $e->attendeeName === 'Dr. Kofi Annan';
    });
});

test('room capacity limits entry unless overridden by staff', function () {
    [$tenant, $user] = eventHost('acme');
    $host = eventSubdomainHost('acme');

    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);
    $session = EventSession::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'title' => 'Intimate Masterclass',
        'capacity' => 1,
    ]);

    $reg1 = EventRegistration::factory()->confirmed()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id]);
    $reg2 = EventRegistration::factory()->confirmed()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id]);

    // Check in first attendee to reach capacity of 1
    EventSessionAttendance::create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'session_id' => $session->id,
        'registration_id' => $reg1->id,
        'checked_in_at' => now(),
    ]);

    // Second attendee tries to check in without override -> blocked (422)
    $blockedRes = $this->actingAs($user)
        ->postJson("http://{$host}/events/{$event->id}/sessions/{$session->id}/scan", [
            'registration_id' => $reg2->id,
            'action' => 'check_in',
            'override_capacity' => false,
        ], ['HTTP_HOST' => $host]);

    $blockedRes->assertStatus(422)
        ->assertJson([
            'is_room_full' => true,
        ]);

    // Second attendee checks in with override -> permitted
    $allowedRes = $this->actingAs($user)
        ->postJson("http://{$host}/events/{$event->id}/sessions/{$session->id}/scan", [
            'registration_id' => $reg2->id,
            'action' => 'check_in',
            'override_capacity' => true,
        ], ['HTTP_HOST' => $host]);

    $allowedRes->assertOk();
    expect($session->liveHeadcount())->toBe(2);
});

test('scanning out records checkout timestamp, dwell time, and CPD contact hours', function () {
    EventFacade::fake([SessionAttendanceUpdated::class]);

    [$tenant, $user] = eventHost('acme');
    $host = eventSubdomainHost('acme');

    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);
    $session = EventSession::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'title' => 'Pediatrics CME Seminar',
        'capacity' => 100,
    ]);

    $reg = EventRegistration::factory()->confirmed()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'full_name' => 'Dr. Efua Sutherland',
    ]);

    $checkedInAt = now()->subMinutes(90); // 90 minutes ago (1.5 hours)
    $attendance = EventSessionAttendance::create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'session_id' => $session->id,
        'registration_id' => $reg->id,
        'checked_in_at' => $checkedInAt,
    ]);

    expect($session->liveHeadcount())->toBe(1);

    $response = $this->actingAs($user)
        ->postJson("http://{$host}/events/{$event->id}/sessions/{$session->id}/scan", [
            'registration_id' => $reg->id,
            'action' => 'check_out',
        ], ['HTTP_HOST' => $host]);

    $response->assertOk()
        ->assertJson([
            'action' => 'check_out',
            'live_headcount' => 0,
            'duration_minutes' => 90,
            'hours_earned' => 1.5,
        ]);

    expect($session->liveHeadcount())->toBe(0)
        ->and($attendance->fresh()->checked_out_at)->not->toBeNull()
        ->and($attendance->fresh()->isCurrentlyInRoom())->toBeFalse();

    EventFacade::assertDispatched(SessionAttendanceUpdated::class);
});

test('session attendance CSV export includes verified scan-in, scan-out, and CPD hours', function () {
    [$tenant, $user] = eventHost('acme');
    $host = eventSubdomainHost('acme');

    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);
    $session = EventSession::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'title' => 'Surgical Robotics Workshop',
        'location' => 'Operating Theatre B',
        'track' => 'Clinical Tech',
    ]);

    $reg = EventRegistration::factory()->confirmed()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'title' => 'Prof',
        'first_name' => 'Kwabena',
        'last_name' => 'Frimpong',
        'full_name' => 'Prof. Kwabena Frimpong',
        'email' => 'k.frimpong@hospital.org',
    ]);

    EventSessionAttendance::create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'session_id' => $session->id,
        'registration_id' => $reg->id,
        'checked_in_at' => now()->subMinutes(120),
        'checked_out_at' => now(),
    ]);

    $response = $this->actingAs($user)
        ->get("http://{$host}/events/{$event->id}/reports/session-attendance", ['HTTP_HOST' => $host]);

    $response->assertOk();
    $content = $response->streamedContent();

    expect($content)->toContain('Session Title')
        ->and($content)->toContain('Location / Room')
        ->and($content)->toContain('Surgical Robotics Workshop')
        ->and($content)->toContain('Operating Theatre B')
        ->and($content)->toContain('k.frimpong@hospital.org')
        ->and($content)->toContain('120') // 120 minutes dwell
        ->and($content)->toContain('2'); // 2.0 CPD hours
});
