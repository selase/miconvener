<?php

declare(strict_types=1);

use App\Models\Event;
use App\Models\EventRegistration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
    [$this->tenant, $this->admin] = eventHost('acme');
    $this->host = eventSubdomainHost('acme');
    $this->event = Event::factory()->published()->create(['tenant_id' => $this->tenant->id]);
    $this->headers = ['HTTP_HOST' => $this->host];
});

test('registration remains visible by default on existing event pages', function (): void {
    expect((new Event)->show_registration)->toBeTrue();
    $this->get("http://{$this->host}/e/{$this->event->slug}", $this->headers)->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('event.show_registration', true));
});

test('hiding new registration preserves verification and ticket issuance for existing attendees', function (): void {
    Mail::fake();
    $this->post("http://{$this->host}/e/{$this->event->slug}/register", [
        'email' => 'existing@example.test', 'full_name' => 'Existing Attendee',
    ], $this->headers)->assertRedirect();
    $registration = $this->event->registrations()->firstOrFail();
    $this->event->update(['show_registration' => false]);
    $url = URL::temporarySignedRoute('public.events.registrations.verify', now()->addDay(), [
        'subdomain' => $this->tenant->slug, 'event' => $this->event->slug, 'registration' => $registration->id,
    ]);
    $this->get($url, $this->headers)->assertRedirect();
    expect($registration->fresh()->ticket_code)->not->toBeNull();
    expect($registration->fresh()->email_verified_at)->not->toBeNull();
});

test('a private event with hidden registration does not invite visitors to register in its share preview', function (): void {
    $this->event->update(['visibility' => 'private', 'show_registration' => false]);
    $card = app(App\Services\Events\EventSocialCard::class)->for($this->event, $this->tenant);
    expect($card['description'])->toBe('You are invited. Open the link for event information.');
});

test('organisers can hide and restore registration without unpublishing or affecting other events', function (): void {
    $other = Event::factory()->published()->create(['tenant_id' => $this->tenant->id]);
    $url = "http://{$this->host}/events/{$this->event->id}/registration-visibility";
    $this->actingAs($this->admin)->patchJson($url, ['show_registration' => false], $this->headers)
        ->assertOk()->assertJsonPath('show_registration', false);
    expect($this->event->fresh()->isPublished())->toBeTrue();
    expect($other->fresh()->show_registration)->toBeTrue();
    $this->get("http://{$this->host}/e/{$this->event->slug}", $this->headers)->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('event.show_registration', false));
    $this->get("http://{$this->host}/events/{$this->event->id}", $this->headers)->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('event.show_registration', false)
            ->where('overview.readiness.0.label', 'Registration hidden'));
    $this->patchJson($url, ['show_registration' => true], $this->headers)->assertOk();
    expect($this->event->fresh()->show_registration)->toBeTrue();
});

test('hidden registration refuses stale public submissions without attendees or mail', function (): void {
    Mail::fake();
    $this->event->update(['show_registration' => false]);
    $this->postJson("http://{$this->host}/e/{$this->event->slug}/register", [
        'email' => 'visitor@example.test', 'full_name' => 'A Visitor',
    ], $this->headers)->assertForbidden();
    expect(EventRegistration::query()->where('event_id', $this->event->id)->count())->toBe(0);
    Mail::assertNothingSent();
    Mail::assertNothingQueued();
});

test('registration visibility requires a boolean and event-management permission within the tenant', function (): void {
    $url = "http://{$this->host}/events/{$this->event->id}/registration-visibility";
    $this->actingAs($this->admin)->patchJson($url, [], $this->headers)->assertUnprocessable();
    $this->patchJson($url, ['show_registration' => 'maybe'], $this->headers)->assertUnprocessable();
    $viewer = App\Models\User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->tenant->users()->attach($viewer->id);
    $this->actingAs($viewer)->patchJson($url, ['show_registration' => false], $this->headers)->assertForbidden();
    [$otherTenant, $outsider] = eventHost('other');
    $this->actingAs($outsider)->patchJson($url, ['show_registration' => false], $this->headers)->assertForbidden();
    $otherEvent = Event::factory()->create(['tenant_id' => $otherTenant->id]);
    $this->actingAs($this->admin)->patchJson("http://{$this->host}/events/{$otherEvent->id}/registration-visibility", ['show_registration' => false], $this->headers)->assertNotFound();
    expect($this->event->fresh()->show_registration)->toBeTrue();
});
