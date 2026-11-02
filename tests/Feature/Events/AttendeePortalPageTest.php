<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Models\Event;
use App\Models\EventRegistration;
use App\Services\Events\PlatformAttendeeVerification;
use App\Services\Tenancy\TenantHostMatcher;
use Illuminate\Support\Facades\Artisan;

/**
 * Pins what the attendee portal receives from the server, so the page can be
 * restructured without anyone having to trust that nothing moved.
 *
 * The portal moved to the platform host, and the registration UUID stopped
 * being a credential, so these now read the payload where it is actually
 * served: the canonical workspace, behind proof of the address.
 */
beforeEach(function () {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
});

function portalFor(string $slug): array
{
    [$tenant] = eventHost($slug);
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);
    $registration = EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'email' => 'ama@stem.org',
        'phone' => '+233201234234',
        'status' => EventRegistration::STATUS_CONFIRMED,
    ]);
    $registration->issueTicket();
    $registration->save();

    return [$event, $registration->fresh(), app(TenantHostMatcher::class)->baseDomain()];
}

function portalProof(string $email = 'ama@stem.org'): array
{
    return [
        PlatformAttendeeVerification::SESSION_KEY => [
            'email' => $email,
            'verified_at' => now()->getTimestamp(),
            'expires_at' => now()->addHours(PlatformAttendeeVerification::VERIFIED_HOURS)->getTimestamp(),
        ],
    ];
}

test('joining an event requires existing email verification and preserves the requested activity', function (): void {
    [$event, $registration, $host] = portalFor('join-proof');
    $path = '/my/join/'.$event->id.'?tab=forms';
    $this->get("http://{$host}{$path}", ['HTTP_HOST' => $host])
        ->assertRedirect('/my?return_to='.urlencode($path));
    $this->withSession(portalProof())->get("http://{$host}{$path}", ['HTTP_HOST' => $host])
        ->assertRedirect("http://{$host}/my/events/{$registration->id}?tab=forms");
});

test('joining cannot choose another persons registration and fails safely for a different email', function (): void {
    [$event, $registration, $host] = portalFor('join-other-email');
    $this->withSession(portalProof('different@example.test'))
        ->get("http://{$host}/my/join/{$event->id}?tab=poll", ['HTTP_HOST' => $host])
        ->assertRedirect(route('public.events.show', ['subdomain' => $event->tenant->slug, 'event' => $event->slug]).'?attendee_area=register');
});

test('joining an event that has already finished only explains, instead of inviting registration', function (): void {
    [$event, , $host] = portalFor('join-finished');
    $event->update(['starts_at' => now()->subDays(3), 'ends_at' => now()->subDays(3)->addHours(3)]);

    $this->withSession(portalProof('different@example.test'))
        ->get("http://{$host}/my/join/{$event->id}", ['HTTP_HOST' => $host])
        ->assertRedirect('/my?event_access=not_found');
});

test('the verify page offers registration for the upcoming event being joined and nothing for any other return', function (): void {
    [$event, , $host] = portalFor('join-offer');
    $registerUrl = route('public.events.show', ['subdomain' => $event->tenant->slug, 'event' => $event->slug]).'?attendee_area=register';

    $this->get("http://{$host}/my?return_to=".urlencode('/my/join/'.$event->id.'?tab=forms'), ['HTTP_HOST' => $host])->assertOk()
        ->assertInertia(fn ($page) => $page->where('joinEvent.name', $event->name)->where('joinEvent.register_url', $registerUrl));
    $this->get("http://{$host}/my?return_to=".urlencode('/my/store-orders'), ['HTTP_HOST' => $host])->assertOk()
        ->assertInertia(fn ($page) => $page->where('joinEvent', null));
    $this->get("http://{$host}/my?return_to=".urlencode('/my/join/not-a-uuid'), ['HTTP_HOST' => $host])->assertOk()
        ->assertInertia(fn ($page) => $page->where('joinEvent', null));
    $this->get("http://{$host}/my?return_to=".urlencode('/my/join/'.(string) \Illuminate\Support\Str::uuid()), ['HTTP_HOST' => $host])->assertOk()
        ->assertInertia(fn ($page) => $page->where('joinEvent', null));

    $event->update(['starts_at' => now()->subDays(3), 'ends_at' => now()->subDays(3)->addHours(3)]);
    $this->get("http://{$host}/my?return_to=".urlencode('/my/join/'.$event->id), ['HTTP_HOST' => $host])->assertOk()
        ->assertInertia(fn ($page) => $page->where('joinEvent', null));
});

test('joining ignores invalid tabs and never revives cancelled registrations', function (): void {
    [$event, $registration, $host] = portalFor('join-tab');
    $this->withSession(portalProof())->get("http://{$host}/my/join/{$event->id}?tab=https://evil.test", ['HTTP_HOST' => $host])
        ->assertRedirect("http://{$host}/my/events/{$registration->id}?tab=agenda");
    $registration->update(['status' => EventRegistration::STATUS_CANCELLED]);
    $this->withSession(portalProof())->get("http://{$host}/my/join/{$event->id}", ['HTTP_HOST' => $host])
        ->assertRedirect(route('public.events.show', ['subdomain' => $event->tenant->slug, 'event' => $event->slug]).'?attendee_area=register');
});

test('workspace deep links are passed as a safe initial tab without changing its authorization', function (): void {
    [$event, $registration, $host] = portalFor('workspace-tab');
    $this->withSession(portalProof())->get("http://{$host}/my/events/{$registration->id}?tab=forms", ['HTTP_HOST' => $host])
        ->assertOk()->assertInertia(fn ($page) => $page->where('initial_tab', 'forms'));
    $this->withSession(portalProof('someoneelse@example.test'))->get("http://{$host}/my/events/{$registration->id}?tab=forms", ['HTTP_HOST' => $host])
        ->assertForbidden();
});

test('expired timed quizzes are not advertised as active to attendees', function (): void {
    [$event, $registration, $host] = portalFor('expired-live-poll');
    \App\Models\EventPoll::factory()->quiz()->live()->create([
        'tenant_id' => $event->tenant_id,
        'event_id' => $event->id,
        'went_live_at' => now()->subMinutes(2),
    ]);
    $this->withSession(portalProof())->get("http://{$host}/my/events/{$registration->id}", ['HTTP_HOST' => $host])
        ->assertOk()->assertInertia(fn ($page) => $page->where('has_live_poll', false));
});

test('the portal page receives the registration, the event and what can be acted on', function () {
    [$event, $registration, $host] = portalFor('portal-props');

    $this->withSession(portalProof())
        ->get("http://{$host}/my/events/{$registration->id}", ['HTTP_HOST' => $host])
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Public/Events/AttendeePortal/Portal')
            ->where('registration.id', $registration->id)
            ->where('registration.status', EventRegistration::STATUS_CONFIRMED)
            ->has('registration.ticket_code')
            ->has('event.slug')
            ->has('materials')
            ->has('canRequestHelp'));
});

test('the portal masks the address and does not send the phone number', function () {
    [$event, $registration, $host] = portalFor('portal-masked');

    // The address is masked even for the person who proved it: the page is read
    // in rooms, on shared screens and over shoulders, and it never needs the
    // phone number at all.
    $this->withSession(portalProof())
        ->get("http://{$host}/my/events/{$registration->id}", ['HTTP_HOST' => $host])
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('registration.email', 'am•@stem.org')
            ->missing('registration.phone'));
});
