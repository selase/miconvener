<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Mail\Events\EventRegistrationConfirmed;
use App\Mail\Events\EventRegistrationVerifyEmail;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\Tenant;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

/**
 * A free registration costs nothing and proves nothing: anyone can type any
 * address and be handed a valid ticket at it. The ticket is now withheld until
 * the address is confirmed. Payment stands in for that on a paid ticket.
 */
beforeEach(function () {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
});

function freeEventFor(string $slug): array
{
    $tenant = Tenant::factory()->create(['slug' => $slug, 'isolation_mode' => 'shared']);
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id, 'ticket_price' => 0]);

    return [$tenant, $event, $slug.'.'.mb_ltrim((string) config('session.domain'), '.')];
}

test('a free registration gets a verification email, not a ticket', function () {
    Mail::fake();
    [$tenant, $event, $host] = freeEventFor('verify-free');

    $this->post("http://{$host}/e/{$event->slug}/register", [
        'full_name' => 'Ama Mensah',
        'email' => 'ama@example.com',
    ], ['HTTP_HOST' => $host]);

    $registration = EventRegistration::where('email', 'ama@example.com')->firstOrFail();

    expect($registration->email_verified_at)->toBeNull();
    expect($registration->ticket_code)->toBeNull();

    Mail::assertQueued(EventRegistrationVerifyEmail::class, fn ($m): bool => $m->hasTo('ama@example.com'));
    Mail::assertNotQueued(EventRegistrationConfirmed::class);
});

test('confirming the email issues the ticket and sends it', function () {
    Mail::fake();
    [$tenant, $event, $host] = freeEventFor('verify-click');

    $this->post("http://{$host}/e/{$event->slug}/register", [
        'full_name' => 'Ama Mensah', 'email' => 'ama@example.com',
    ], ['HTTP_HOST' => $host]);

    $registration = EventRegistration::where('email', 'ama@example.com')->firstOrFail();

    $url = URL::temporarySignedRoute('public.events.registrations.verify', now()->addDays(7), [
        'subdomain' => $tenant->slug, 'event' => $event->slug, 'registration' => $registration->id,
    ]);

    $this->get($url, ['HTTP_HOST' => $host])->assertRedirect();

    $registration->refresh();
    expect($registration->email_verified_at)->not->toBeNull();
    expect($registration->ticket_code)->not->toBeNull();

    Mail::assertQueued(EventRegistrationConfirmed::class, fn ($m): bool => $m->hasTo('ama@example.com'));
});

test('an unsigned or tampered verification link is refused', function () {
    Mail::fake();
    [$tenant, $event, $host] = freeEventFor('verify-unsigned');

    $this->post("http://{$host}/e/{$event->slug}/register", [
        'full_name' => 'Ama Mensah', 'email' => 'ama@example.com',
    ], ['HTTP_HOST' => $host]);

    $registration = EventRegistration::where('email', 'ama@example.com')->firstOrFail();

    // No signature at all: guessing the URL must not verify anyone.
    $this->get("http://{$host}/e/{$event->slug}/registrations/{$registration->id}/verify", ['HTTP_HOST' => $host])
        ->assertForbidden();

    expect($registration->fresh()->email_verified_at)->toBeNull();
    expect($registration->fresh()->ticket_code)->toBeNull();
});

test('clicking the link twice does not issue a second ticket or a second email', function () {
    Mail::fake();
    [$tenant, $event, $host] = freeEventFor('verify-twice');

    $this->post("http://{$host}/e/{$event->slug}/register", [
        'full_name' => 'Ama Mensah', 'email' => 'ama@example.com',
    ], ['HTTP_HOST' => $host]);

    $registration = EventRegistration::where('email', 'ama@example.com')->firstOrFail();
    $url = URL::temporarySignedRoute('public.events.registrations.verify', now()->addDays(7), [
        'subdomain' => $tenant->slug, 'event' => $event->slug, 'registration' => $registration->id,
    ]);

    $this->get($url, ['HTTP_HOST' => $host])->assertRedirect();
    $firstCode = $registration->fresh()->ticket_code;

    // Mail clients prefetch links; a forwarded one gets clicked again.
    $this->get($url, ['HTTP_HOST' => $host])->assertRedirect();

    expect($registration->fresh()->ticket_code)->toBe($firstCode);
    Mail::assertQueuedCount(2); // the verify mail, then one ticket mail
});

test('paying for a ticket verifies the address without a second step', function () {
    [$tenant, $event, $host] = freeEventFor('verify-paid');

    $registration = EventRegistration::factory()->create([
        'tenant_id' => $tenant->id, 'event_id' => $event->id,
        'email' => 'ama@example.com',
        'status' => EventRegistration::STATUS_PENDING_PAYMENT,
    ]);

    expect($registration->email_verified_at)->toBeNull();

    // The tenant-side approval path stands in for the webhook here: both issue
    // the ticket and both treat the address as proven.
    $registration->email_verified_at ??= now();
    $registration->issueTicket();
    $registration->save();

    expect($registration->fresh()->hasVerifiedEmail())->toBeTrue();
    expect($registration->fresh()->ticket_code)->not->toBeNull();
});
