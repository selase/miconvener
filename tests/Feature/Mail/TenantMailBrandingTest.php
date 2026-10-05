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
use App\Models\TenantSendingDomain;
use Database\Seeders\EventPackageSeeder;
use Illuminate\Support\Facades\Config;

/**
 * An attendee gets mail about someone else's conference. The name in their
 * inbox should be the organizer they registered with, not the software the
 * organizer happens to use, and a reply should reach that organizer rather
 * than a noreply mailbox nobody reads.
 *
 * The envelope address stays on our own sending domain unless the organiser
 * is on Enterprise and SES has verified their own domain.
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
    $registration = brandingRegistrationFor(brandingTenantOnPlan('starter'));

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
    $tenant = brandingTenantOnPlan('starter');
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

test('an Enterprise organiser whose own domain SES has verified sends from their own address', function () {
    $tenant = brandingTenantOnPlan('enterprise');
    TenantSendingDomain::query()->create([
        'tenant_id' => $tenant->id,
        'domain' => 'events.techsummit.gh',
        'from_address' => 'hello@events.techsummit.gh',
        'status' => TenantSendingDomain::STATUS_VERIFIED,
    ]);

    $envelope = new EventTicketLink(brandingRegistrationFor($tenant))->envelope();

    expect($envelope->from->address)->toBe('hello@events.techsummit.gh')
        ->and($envelope->from->name)->toBe('Tech Summit Ghana')
        ->and($envelope->replyTo[0]->address)->toBe('organizers@techsummit.gh');
});

test('an own domain that is not verified, or not on Enterprise, sends from the platform address', function (string $plan, string $status) {
    $tenant = brandingTenantOnPlan($plan);
    TenantSendingDomain::query()->create([
        'tenant_id' => $tenant->id,
        'domain' => 'events.techsummit.gh',
        'from_address' => 'hello@events.techsummit.gh',
        'status' => $status,
    ]);

    $envelope = new EventTicketLink(brandingRegistrationFor($tenant))->envelope();

    expect($envelope->from->address)->toBe('hello@miconvener.com');
})->with([
    'pending on Enterprise' => ['enterprise', TenantSendingDomain::STATUS_PENDING],
    'failed on Enterprise' => ['enterprise', TenantSendingDomain::STATUS_FAILED],
    'verified but no longer on Enterprise' => ['growth', TenantSendingDomain::STATUS_VERIFIED],
]);

test('event mail carries its tenant as an SES message tag', function () {
    $tenant = brandingTenantOnPlan('starter');

    $headers = new EventTicketLink(brandingRegistrationFor($tenant))->headers();

    expect($headers->text)->toBe(['X-SES-MESSAGE-TAGS' => "tenant={$tenant->id}"]);
});
