<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\Tenant;
use App\Services\Events\PlatformAttendeeVerification;
use App\Services\Tenancy\TenantHostMatcher;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\URL;

/**
 * The registration UUID used to be the whole credential: anyone holding the
 * confirmation link saw the ticket. The platform portal moved identity to a
 * proven email address, so the old tenant URLs must stop answering with private
 * data and hand the visitor to the canonical workspace instead.
 */
beforeEach(function (): void {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
});

function handoffBase(): string
{
    return app(TenantHostMatcher::class)->baseDomain();
}

function makeRegistration(string $slug, string $email = 'holder@example.com'): EventRegistration
{
    $tenant = Tenant::factory()->create(['slug' => $slug, 'isolation_mode' => 'shared']);
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);
    $registration = EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'email' => $email,
        'status' => EventRegistration::STATUS_CONFIRMED,
    ]);
    $registration->issueTicket();
    $registration->save();

    return $registration->fresh();
}

test('the legacy confirmation link no longer renders the ticket to whoever holds it', function (): void {
    $registration = makeRegistration('legacy-a');
    $tenant = $registration->tenant;
    $event = $registration->event;
    $host = "{$tenant->slug}.".handoffBase();

    $response = $this->get("http://{$host}/e/{$event->slug}/registrations/{$registration->id}", ['HTTP_HOST' => $host]);

    $response->assertRedirect();
    expect($response->headers->get('Location'))
        ->toBe('http://'.handoffBase()."/my/events/{$registration->id}");

    // And the page it hands off to refuses an unproven visitor.
    $this->get($response->headers->get('Location'), ['HTTP_HOST' => handoffBase()])
        ->assertRedirect();
});

test('the legacy link carries a proven attendee straight through to their ticket', function (): void {
    $registration = makeRegistration('legacy-b', 'holder@example.com');
    $tenant = $registration->tenant;
    $event = $registration->event;
    $host = "{$tenant->slug}.".handoffBase();

    $session = [
        PlatformAttendeeVerification::SESSION_KEY => [
            'email' => 'holder@example.com',
            'verified_at' => now()->getTimestamp(),
            'expires_at' => now()->addHours(12)->getTimestamp(),
        ],
    ];

    $this->withSession($session)
        ->get("http://{$host}/e/{$event->slug}/registrations/{$registration->id}", ['HTTP_HOST' => $host])
        ->assertRedirect('http://'.handoffBase()."/my/events/{$registration->id}");

    $this->withSession($session)
        ->get('http://'.handoffBase()."/my/events/{$registration->id}", ['HTTP_HOST' => handoffBase()])
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('registration.ticket_code', $registration->ticket_code));
});

test('the signed verification link opens that one ticket, and only that one', function (): void {
    $registration = makeRegistration('legacy-c', 'freebie@example.com');
    $registration->update(['email_verified_at' => null]);
    $tenant = $registration->tenant;
    $event = $registration->event;
    $host = "{$tenant->slug}.".handoffBase();

    // The same person is also registered for something else entirely.
    $elsewhere = makeRegistration('legacy-c2', 'freebie@example.com');

    $signed = URL::signedRoute('public.events.registrations.verify', [
        'subdomain' => $tenant->slug,
        'event' => $event->slug,
        'registration' => $registration->id,
    ]);

    $this->get($signed, ['HTTP_HOST' => $host])
        ->assertRedirect('http://'.handoffBase()."/my/events/{$registration->id}");

    expect($registration->fresh()->hasVerifiedEmail())->toBeTrue();

    // It opens the ticket it belongs to, with no second challenge.
    $this->get('http://'.handoffBase()."/my/events/{$registration->id}", ['HTTP_HOST' => handoffBase()])
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('registration.ticket_code', $registration->fresh()->ticket_code));

    // But a link that can be forwarded and lives seven days does not become a
    // pass to this person's history: the other ticket stays shut, and the
    // dashboard still asks who they are.
    $this->get('http://'.handoffBase()."/my/events/{$elsewhere->id}", ['HTTP_HOST' => handoffBase()])
        ->assertRedirect('/my?return_to='.urlencode("/my/events/{$elsewhere->id}"));

    $this->get('http://'.handoffBase().'/my', ['HTTP_HOST' => handoffBase()])
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('verifiedEmail', null));
});

test('the checkout link alone does not open a stranger the private workspace', function (): void {
    $registration = makeRegistration('legacy-d');
    $tenant = $registration->tenant;
    $event = $registration->event;
    $host = "{$tenant->slug}.".handoffBase();

    // A visitor who knows only the UUID. Paying for someone's ticket stays
    // possible; seeing their workspace does not.
    $this->get("http://{$host}/e/{$event->slug}/checkout/{$registration->id}", ['HTTP_HOST' => $host])
        ->assertRedirect();

    $this->get('http://'.handoffBase()."/my/events/{$registration->id}", ['HTTP_HOST' => handoffBase()])
        ->assertRedirect('/my?return_to='.urlencode("/my/events/{$registration->id}"));
});

test('a signed payment invite still grants the payer their workspace', function (): void {
    $registration = makeRegistration('legacy-e');
    $registration->update(['status' => EventRegistration::STATUS_CONFIRMED]);
    $tenant = $registration->tenant;
    $event = $registration->event;
    $host = "{$tenant->slug}.".handoffBase();

    $signed = URL::signedRoute('public.events.checkout', [
        'subdomain' => $tenant->slug,
        'event' => $event->slug,
        'registration' => $registration->id,
    ]);

    $this->get($signed, ['HTTP_HOST' => $host])->assertRedirect();

    $this->get('http://'.handoffBase()."/my/events/{$registration->id}", ['HTTP_HOST' => handoffBase()])
        ->assertOk();
});
