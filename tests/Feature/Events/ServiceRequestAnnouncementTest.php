<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Events\ServiceRequestRaised;
use App\Mail\Events\UrgentServiceRequestRaised;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\EventSeatAssignment;
use App\Models\EventServiceRequest;
use App\Models\EventVenueRoom;
use App\Models\Tenant;
use App\Services\Events\PlatformAttendeeVerification;
use App\Services\Tenancy\TenantHostMatcher;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event as EventFacade;
use Illuminate\Support\Facades\Mail;

/**
 * A request written to a table that nobody is watching helps no one. Every one
 * is announced to the console; an urgent one is also mailed, because the case
 * worth designing for is the one where the screen is unattended.
 */
beforeEach(function (): void {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
    Mail::fake();
});

function helpRequestScenario(string $slug, string $organiserEmail = 'ops@organiser.test'): array
{
    $tenant = Tenant::factory()->create([
        'slug' => $slug,
        'isolation_mode' => 'shared',
        'email' => $organiserEmail,
    ]);
    $event = Event::factory()->published()->create([
        'tenant_id' => $tenant->id,
        'starts_at' => now()->subHour(),
        'ends_at' => now()->addHours(4),
    ]);
    $registration = EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'email' => 'attendee@example.com',
        'full_name' => 'Ama Serwaa',
        'status' => EventRegistration::STATUS_CHECKED_IN,
        'checked_in_at' => now()->subMinutes(30),
    ]);

    $room = EventVenueRoom::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'name' => 'Main Auditorium',
        'rows' => 10,
        'seats_per_row' => 20,
    ]);
    EventSeatAssignment::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'room_id' => $room->id,
        'registration_id' => $registration->id,
        'seat_label' => 'C-14',
    ]);

    return [$tenant, $event, $registration];
}

function raiseRequest(object $test, EventRegistration $registration, string $type): \Illuminate\Testing\TestResponse
{
    $host = app(TenantHostMatcher::class)->baseDomain();

    return $test->withSession([
        PlatformAttendeeVerification::SESSION_KEY => [
            'email' => $registration->email,
            'verified_at' => now()->getTimestamp(),
            'expires_at' => now()->addHours(12)->getTimestamp(),
        ],
    ])->postJson("http://{$host}/my/events/{$registration->id}/service-requests", [
        'type' => $type,
    ], ['HTTP_HOST' => $host]);
}

test('every request is announced to the console the moment it is raised', function (): void {
    EventFacade::fake([ServiceRequestRaised::class]);

    [, $event, $registration] = helpRequestScenario('announce-water');

    raiseRequest($this, $registration, EventServiceRequest::TYPE_REFRESHMENT)->assertCreated();

    EventFacade::assertDispatched(
        ServiceRequestRaised::class,
        function (ServiceRequestRaised $broadcast) use ($event, $registration): bool {
            $payload = $broadcast->broadcastWith();

            return $broadcast->serviceRequest->event_id === $event->id
                // The seat travels with it, so a steward knows where to go.
                && $payload['seat_label'] === 'C-14'
                && $payload['room_name'] === 'Main Auditorium'
                && $payload['registrant_name'] === $registration->full_name
                && $broadcast->broadcastOn()[0]->name === "private-event.{$event->id}.service-requests";
        }
    );
});

test('an ordinary request does not reach anyone by email', function (): void {
    [, , $registration] = helpRequestScenario('announce-no-mail');

    raiseRequest($this, $registration, EventServiceRequest::TYPE_REFRESHMENT)->assertCreated();

    Mail::assertNothingQueued();
});

test('a medical request is emailed to the organiser as well', function (): void {
    [, , $registration] = helpRequestScenario('announce-medical');

    raiseRequest($this, $registration, EventServiceRequest::TYPE_MEDICAL)->assertCreated();

    Mail::assertQueued(
        UrgentServiceRequestRaised::class,
        fn (UrgentServiceRequestRaised $mail): bool => $mail->hasTo('ops@organiser.test')
            && $mail->serviceRequest->priority === EventServiceRequest::PRIORITY_URGENT
    );
});

test('an organisation with no address on file is simply not mailed', function (): void {
    [, , $registration] = helpRequestScenario('announce-no-address', '');

    raiseRequest($this, $registration, EventServiceRequest::TYPE_MEDICAL)->assertCreated();

    Mail::assertNothingQueued();

    // The request still exists and still reaches the console.
    expect(EventServiceRequest::withoutGlobalScopes()->where('registration_id', $registration->id)->count())->toBe(1);
});
