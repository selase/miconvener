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
