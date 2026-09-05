<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\EventSession;
use App\Models\Speaker;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

beforeEach(function () {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
});

function scheduleHost(): array
{
    $tenant = Tenant::factory()->create(['slug' => 'acme', 'isolation_mode' => 'shared']);
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    setPermissionsTeamId($tenant->id);
    $user->assignRole('Org Superadmin');
    $tenant->users()->attach($user->id);

    return [$tenant, $user];
}

test('creating a session in the same room at an overlapping time returns a clash warning', function () {
    [$tenant, $user] = scheduleHost();
    $event = Event::factory()->create(['tenant_id' => $tenant->id]);
    EventSession::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'title' => 'Opening keynote',
        'location' => 'Main Hall',
        'starts_at' => '2026-12-01 09:00:00',
        'ends_at' => '2026-12-01 10:00:00',
    ]);

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "acme.{$baseDomain}";

    $response = $this->actingAs($user)->postJson("http://{$host}/events/{$event->id}/sessions", [
        'title' => 'Surprise clash',
        'location' => 'Main Hall',
        'starts_at' => '2026-12-01 09:30:00',
        'ends_at' => '2026-12-01 10:30:00',
        'type' => 'session',
    ], ['HTTP_HOST' => $host]);

    $response->assertOk();
    expect($response->json('clashes'))->toHaveCount(1);
    expect($response->json('clashes.0.title'))->toBe('Opening keynote');
});

test('a shared speaker at an overlapping time in a different room still clashes', function () {
    [$tenant, $user] = scheduleHost();
    $event = Event::factory()->create(['tenant_id' => $tenant->id]);
    $speaker = Speaker::factory()->create(['tenant_id' => $tenant->id]);
    $existing = EventSession::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'title' => 'Room A talk',
        'location' => 'Room A',
        'starts_at' => '2026-12-01 09:00:00',
        'ends_at' => '2026-12-01 10:00:00',
    ]);
    $existing->speakers()->attach($speaker->id, ['id' => (string) Str::uuid()]);

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "acme.{$baseDomain}";

    $response = $this->actingAs($user)->postJson("http://{$host}/events/{$event->id}/sessions", [
        'title' => 'Room B talk',
        'location' => 'Room B',
        'starts_at' => '2026-12-01 09:30:00',
        'ends_at' => '2026-12-01 10:30:00',
        'type' => 'session',
        'speaker_ids' => [$speaker->id],
    ], ['HTTP_HOST' => $host]);

    $response->assertOk();
    expect($response->json('clashes'))->toHaveCount(1);
    expect($response->json('clashes.0.title'))->toBe('Room A talk');
});

test('sessions on different days or without an overlap do not clash', function () {
    [$tenant, $user] = scheduleHost();
    $event = Event::factory()->create(['tenant_id' => $tenant->id]);
    EventSession::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'location' => 'Main Hall',
        'starts_at' => '2026-12-01 09:00:00',
        'ends_at' => '2026-12-01 10:00:00',
    ]);

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "acme.{$baseDomain}";

    $response = $this->actingAs($user)->postJson("http://{$host}/events/{$event->id}/sessions", [
        'title' => 'No clash',
        'location' => 'Main Hall',
        'starts_at' => '2026-12-01 10:00:00',
        'ends_at' => '2026-12-01 11:00:00',
        'type' => 'session',
    ], ['HTTP_HOST' => $host]);

    $response->assertOk();
    expect($response->json('clashes'))->toHaveCount(0);
});

test('reordering a group of sessions lays them out back to back preserving durations', function () {
    [$tenant, $user] = scheduleHost();
    $event = Event::factory()->create(['tenant_id' => $tenant->id]);
    $first = EventSession::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id, 'title' => 'A', 'starts_at' => '2026-12-01 09:00:00', 'ends_at' => '2026-12-01 09:30:00']);
    $second = EventSession::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id, 'title' => 'B', 'starts_at' => '2026-12-01 09:30:00', 'ends_at' => '2026-12-01 10:15:00']);

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "acme.{$baseDomain}";

    // Swap B before A.
    $response = $this->actingAs($user)->patchJson("http://{$host}/events/{$event->id}/sessions/reorder", [
        'session_ids' => [$second->id, $first->id],
    ], ['HTTP_HOST' => $host]);

    $response->assertOk();

    $second->refresh();
    $first->refresh();

    expect($second->starts_at->toDateTimeString())->toBe('2026-12-01 09:00:00');
    expect($second->ends_at->toDateTimeString())->toBe('2026-12-01 09:45:00'); // kept its own 45-minute duration
    expect($first->starts_at->toDateTimeString())->toBe('2026-12-01 09:45:00');
    expect($first->ends_at->toDateTimeString())->toBe('2026-12-01 10:15:00'); // kept its own 30-minute duration
});

test('a registrant cannot add a full-capacity session to their day', function () {
    [$tenant] = scheduleHost();
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);
    $session = EventSession::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id, 'capacity' => 1]);
    $existingAttendee = EventRegistration::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id]);
    $existingAttendee->sessions()->attach($session->id, ['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id]);

    $newAttendee = EventRegistration::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id]);

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "acme.{$baseDomain}";

    $this->postJson("http://{$host}/e/{$event->slug}/registrations/{$newAttendee->id}/agenda/{$session->id}", [], ['HTTP_HOST' => $host])
        ->assertStatus(422);

    // The person already in still shows as signed up (idempotent, not blocked by their own seat).
    $this->postJson("http://{$host}/e/{$event->slug}/registrations/{$existingAttendee->id}/agenda/{$session->id}", [], ['HTTP_HOST' => $host])
        ->assertOk();
});

test('the public programme ICS download contains every session', function () {
    [$tenant] = scheduleHost();
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);
    EventSession::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id, 'title' => 'Opening keynote']);

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "acme.{$baseDomain}";

    $response = $this->get("http://{$host}/e/{$event->slug}/schedule.ics", ['HTTP_HOST' => $host]);

    $response->assertOk();
    $response->assertHeader('Content-Type', 'text/calendar; charset=utf-8');
    expect($response->getContent())->toContain('BEGIN:VCALENDAR');
    expect($response->getContent())->toContain('SUMMARY:Opening keynote');
});

test('the personal agenda ICS only contains sessions the registrant added to their day', function () {
    [$tenant] = scheduleHost();
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);
    $inAgenda = EventSession::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id, 'title' => 'In my day']);
    EventSession::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id, 'title' => 'Not in my day']);
    $registration = EventRegistration::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id]);
    $registration->sessions()->attach($inAgenda->id, ['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id]);

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "acme.{$baseDomain}";

    $response = $this->get("http://{$host}/e/{$event->slug}/registrations/{$registration->id}/agenda.ics", ['HTTP_HOST' => $host]);

    $response->assertOk();
    expect($response->getContent())->toContain('SUMMARY:In my day');
    expect($response->getContent())->not->toContain('SUMMARY:Not in my day');
});
