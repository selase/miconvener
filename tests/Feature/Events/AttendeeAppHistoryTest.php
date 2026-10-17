<?php

declare(strict_types=1);

use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\Tenant;
use App\Services\Events\PlatformAttendeeHistory;
use App\Services\Events\PlatformAttendeeVerification;

it('paginates only the proven emails events and keeps ended checked-in events out of live now', function (): void {
    $tenant = Tenant::factory()->create();
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id, 'starts_at' => now()->addDay(), 'ends_at' => now()->addDays(2)]);
    EventRegistration::factory()->count(23)->confirmed()->sequence(fn ($sequence) => ['ticket_code' => 'APP-'.$sequence->index, 'qr_token' => 'qr-'.$sequence->index])->create(['event_id' => $event->id, 'tenant_id' => $tenant->id, 'email' => 'owner@example.test']);
    EventRegistration::factory()->confirmed()->create(['event_id' => $event->id, 'tenant_id' => $tenant->id, 'email' => 'other@example.test']);
    $history = app(PlatformAttendeeHistory::class)->getAppHistory('owner@example.test', ['period' => 'upcoming']);
    expect($history['passes']['total'])->toBe(23)->and($history['passes']['data'])->toHaveCount(20);
    $ended = Event::factory()->published()->create(['tenant_id' => $tenant->id, 'starts_at' => now()->subDays(2), 'ends_at' => now()->subDay()]);
    EventRegistration::factory()->checkedIn()->create(['event_id' => $ended->id, 'tenant_id' => $tenant->id, 'email' => 'owner@example.test']);
    expect(app(PlatformAttendeeHistory::class)->getAppHistory('owner@example.test')['live_now'])->toBe([]);
});

it('protects ticket pass details with current email access and excludes unsafe private data', function (): void {
    $tenant = Tenant::factory()->create();
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);
    $registration = EventRegistration::factory()->confirmed()->create(['event_id' => $event->id, 'tenant_id' => $tenant->id, 'email' => 'owner@example.test', 'email_verified_at' => now()]);
    $host = mb_ltrim((string) config('session.domain'), '.');
    $url = "http://{$host}/my/app/tickets/{$registration->id}";
    $this->getJson($url, ['HTTP_HOST' => $host])->assertUnauthorized();
    $proof = [PlatformAttendeeVerification::SESSION_KEY => ['email' => 'owner@example.test', 'verified_at' => now()->timestamp, 'expires_at' => now()->addHour()->timestamp]];
    $this->withSession($proof)->getJson($url, ['HTTP_HOST' => $host])->assertOk()->assertJsonStructure(['registration' => ['id', 'qr_image', 'ticket_code'], 'event' => ['name']])->assertDontSee('owner@example.test');
    $registration->update(['email' => 'changed@example.test']);
    $this->withSession($proof)->getJson($url, ['HTTP_HOST' => $host])->assertUnauthorized();
});

it('withholds private event media from pending registrations and never gives them a pass', function (): void {
    $tenant = Tenant::factory()->create();
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id, 'visibility' => 'private', 'hero_image_path' => 'events/private.jpg', 'address' => 'Private venue']);
    $registration = EventRegistration::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id, 'email' => 'pending@example.test', 'status' => EventRegistration::STATUS_PENDING_PAYMENT]);
    $history = app(PlatformAttendeeHistory::class)->getAppHistory('pending@example.test');
    expect($history['passes']['data'][0]['event']['hero_image_url'])->toBeNull()
        ->and($history['passes']['data'][0]['event']['location'])->toBeNull()
        ->and($history['passes']['data'][0]['available_actions'])->not->toContain('view_ticket');
    $host = mb_ltrim((string) config('session.domain'), '.');
    $proof = [PlatformAttendeeVerification::SESSION_KEY => ['email' => 'pending@example.test', 'verified_at' => now()->timestamp, 'expires_at' => now()->addHour()->timestamp]];
    $this->withSession($proof)->getJson("http://{$host}/my/app/tickets/{$registration->id}", ['HTTP_HOST' => $host])->assertForbidden();
    $this->withSession($proof)->getJson("http://{$host}/my/app/events?period=wrong&page=0", ['HTTP_HOST' => $host])->assertUnprocessable();
});
