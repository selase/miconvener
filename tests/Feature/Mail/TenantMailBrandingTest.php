<?php

declare(strict_types=1);

use App\Mail\Billing\PaymentReceiptMail;
use App\Mail\Events\EventBlastMail;
use App\Mail\Events\EventRegistrationConfirmed;
use App\Mail\Events\EventTicketLink;
use App\Models\Event;
use App\Models\EventBlast;
use App\Models\EventRegistration;
use App\Models\Package;
use App\Models\Tenant;
use Database\Seeders\EventPackageSeeder;
use Illuminate\Support\Facades\Config;

/**
 * An attendee gets mail about someone else's conference. The name in their
 * inbox should be the organizer they registered with, not the software the
 * organizer happens to use, and a reply should reach that organizer rather
 * than a noreply mailbox nobody reads.
 *
 * The envelope address itself stays on our own sending domain throughout: it
 * is the only address the mail provider will accept, and changing it is a
 * separate feature that needs the organizer's domain verified.
 */
beforeEach(function (): void {
    refreshTenantDatabases();
    $this->seed(EventPackageSeeder::class);

    Config::set('app.name', 'MiConvener');
    Config::set('mail.from.address', 'hello@miconvener.com');
});

function brandingTenantOnPlan(string $plan, array $attributes = []): Tenant
{
    $tenant = Tenant::factory()->create([
        'name' => 'Tech Summit Ghana',
        'email' => 'organizers@techsummit.gh',
        'isolation_mode' => 'shared',
        'package_id' => Package::where('slug', $plan)->firstOrFail()->id,
        ...$attributes,
    ]);

    // Plan gates read the tenant's own feature rows, which provisioning
    // materialises from the package. Without this a tenant has no rows at all
    // and every gate reads as allowed.
    $tenant->syncFeaturesFromPackage();

    return $tenant;
}

function brandingRegistrationFor(Tenant $tenant, array $eventAttributes = []): EventRegistration
{
    $event = Event::factory()->create(['tenant_id' => $tenant->id, ...$eventAttributes]);

    return EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
    ]);
}

test('an attendee sees the organizer, credited to the platform, on a plan without white label', function () {
    $registration = brandingRegistrationFor(brandingTenantOnPlan('growth'));

    $envelope = new EventTicketLink($registration)->envelope();

    expect($envelope->from->name)->toBe('Tech Summit Ghana via MiConvener');
});

test('white label drops the platform, leaving the organizer alone in the inbox', function () {
    $registration = brandingRegistrationFor(brandingTenantOnPlan('enterprise'));

    $envelope = new EventTicketLink($registration)->envelope();

    expect($envelope->from->name)->toBe('Tech Summit Ghana');
});

test('the envelope address stays on our own sending domain whatever the plan', function (string $plan) {
    $registration = brandingRegistrationFor(brandingTenantOnPlan($plan));

    $envelope = new EventTicketLink($registration)->envelope();

    // Sending as the organizer's own address needs their domain verified with
    // the mail provider. Until then this address is the only one accepted.
    expect($envelope->from->address)->toBe('hello@miconvener.com');
})->with(['growth', 'enterprise']);

test('a reply reaches the organization when the event names no address of its own', function () {
    $registration = brandingRegistrationFor(brandingTenantOnPlan('growth'), ['contact_email' => null]);

    $envelope = new EventTicketLink($registration)->envelope();

    expect($envelope->replyTo)->toHaveCount(1);
    expect($envelope->replyTo[0]->address)->toBe('organizers@techsummit.gh');
});

test('an event may route its own replies somewhere else', function () {
    $registration = brandingRegistrationFor(brandingTenantOnPlan('growth'), ['contact_email' => 'tickets@techsummit.gh']);

    $envelope = new EventTicketLink($registration)->envelope();

    expect($envelope->replyTo[0]->address)->toBe('tickets@techsummit.gh');
});

test('a tenant with no address on file gets no reply-to rather than a broken one', function () {
    $registration = brandingRegistrationFor(brandingTenantOnPlan('growth', ['email' => null]));

    $envelope = new EventTicketLink($registration)->envelope();

    expect($envelope->replyTo)->toBe([]);
});

test('every mail an attendee receives about an event carries the branding, not just one', function () {
    $tenant = brandingTenantOnPlan('growth');
    $registration = brandingRegistrationFor($tenant);
    $blast = EventBlast::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $registration->event_id,
        'subject' => 'Doors open at 8',
    ]);

    $envelopes = [
        new EventTicketLink($registration)->envelope(),
        new EventRegistrationConfirmed($registration)->envelope(),
        new EventBlastMail($blast, 'Ama', (string) $registration->id)->envelope(),
    ];

    foreach ($envelopes as $envelope) {
        expect($envelope->from->name)->toBe('Tech Summit Ghana via MiConvener');
    }
});

test('billing mail keeps our identity, because we are the ones who charged the card', function () {
    $tenant = brandingTenantOnPlan('enterprise');

    // Even on white label: a receipt that looked like it came from the
    // organizer would misstate who took the money.
    expect(class_uses_recursive(PaymentReceiptMail::class))
        ->not->toContain(App\Mail\Concerns\BrandedForTenant::class);

    expect($tenant->planAllows('white_label'))->toBeTrue();
});
