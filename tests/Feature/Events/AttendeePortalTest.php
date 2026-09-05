<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Mail\Events\EventRegistrationConfirmed;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\EventSession;
use App\Models\Tenant;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
});

test('an attendee can add and remove a session from their personal agenda', function () {
    $tenant = Tenant::factory()->create(['slug' => 'acme', 'isolation_mode' => 'shared']);
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);
    $session = EventSession::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id]);
    $registration = EventRegistration::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id]);

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "acme.{$baseDomain}";

    $this->postJson("http://{$host}/e/{$event->slug}/registrations/{$registration->id}/agenda/{$session->id}", [], ['HTTP_HOST' => $host])
        ->assertOk();

    expect($registration->fresh()->sessions()->pluck('event_sessions.id'))->toContain($session->id);

    $this->deleteJson("http://{$host}/e/{$event->slug}/registrations/{$registration->id}/agenda/{$session->id}", [], ['HTTP_HOST' => $host])
        ->assertOk();

    expect($registration->fresh()->sessions()->pluck('event_sessions.id'))->not->toContain($session->id);
});

test('an attendee can transfer their ticket to someone else, and a fresh confirmation is emailed', function () {
    Mail::fake();
    $tenant = Tenant::factory()->create(['slug' => 'acme', 'isolation_mode' => 'shared']);
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);
    $registration = EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'full_name' => 'Original Holder',
        'email' => 'original@example.com',
    ]);

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "acme.{$baseDomain}";

    $response = $this->postJson("http://{$host}/e/{$event->slug}/registrations/{$registration->id}/transfer", [
        'full_name' => 'New Holder',
        'email' => 'new-holder@example.com',
    ], ['HTTP_HOST' => $host]);

    $response->assertOk();
    expect($registration->fresh()->full_name)->toBe('New Holder');
    expect($registration->fresh()->email)->toBe('new-holder@example.com');
    Mail::assertQueued(EventRegistrationConfirmed::class, fn ($mail) => $mail->hasTo('new-holder@example.com'));
});

test('a checked-in ticket cannot be transferred', function () {
    $tenant = Tenant::factory()->create(['slug' => 'acme', 'isolation_mode' => 'shared']);
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);
    $registration = EventRegistration::factory()->checkedIn()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id]);

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "acme.{$baseDomain}";

    $this->postJson("http://{$host}/e/{$event->slug}/registrations/{$registration->id}/transfer", [
        'full_name' => 'New Holder',
        'email' => 'new-holder@example.com',
    ], ['HTTP_HOST' => $host])->assertStatus(422);

    expect($registration->fresh()->full_name)->not->toBe('New Holder');
});
