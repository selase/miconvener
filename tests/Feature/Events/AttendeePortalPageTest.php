<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Models\Event;
use App\Models\EventRegistration;
use Illuminate\Support\Facades\Artisan;

/**
 * Pins what the attendee portal receives from the server, so the page can be
 * restructured without anyone having to trust that nothing moved.
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

    return [$event, $registration, eventSubdomainHost($slug)];
}

test('the portal page receives the registration, the event and what can be acted on', function () {
    [$event, $registration, $host] = portalFor('portal-props');

    $this->get("http://{$host}/e/{$event->slug}/registrations/{$registration->id}", ['HTTP_HOST' => $host])
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

    // Anyone holding the link sees this page. A forwarded confirmation email
    // should hand over a ticket, not the holder's contact details.
    $this->get("http://{$host}/e/{$event->slug}/registrations/{$registration->id}", ['HTTP_HOST' => $host])
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('registration.email', 'am•@stem.org')
            ->missing('registration.phone'));
});
