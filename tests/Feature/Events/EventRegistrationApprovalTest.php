<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Mail\Events\EventRegistrationConfirmed;
use App\Mail\Events\EventRegistrationPaymentInvite;
use App\Mail\Events\EventRegistrationPendingApproval;
use App\Mail\Events\EventRegistrationRejected;
use App\Mail\Events\EventRegistrationWaitlisted;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\EventTicketType;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
});

function approvalHost(): array
{
    $tenant = Tenant::factory()->create(['slug' => 'acme', 'isolation_mode' => 'shared']);
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    setPermissionsTeamId($tenant->id);
    $user->assignRole('Org Superadmin');
    $tenant->users()->attach($user->id);

    return [$tenant, $user];
}

test('registering for an event that requires approval lands as pending approval, not confirmed', function () {
    Mail::fake();
    [$tenant] = approvalHost();
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id, 'requires_approval' => true]);

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "acme.{$baseDomain}";

    $this->post("http://{$host}/e/{$event->slug}/register", [
        'full_name' => 'Ama Boateng',
        'email' => 'ama@example.com',
    ], ['HTTP_HOST' => $host])->assertRedirect();

    $registration = EventRegistration::where('email', 'ama@example.com')->firstOrFail();
    expect($registration->status)->toBe(EventRegistration::STATUS_PENDING_APPROVAL);
    expect($registration->ticket_code)->toBeNull();

    Mail::assertQueued(EventRegistrationPendingApproval::class);
});

test('approving a free pending registration confirms it and issues a ticket', function () {
    Mail::fake();
    [$tenant, $user] = approvalHost();
    $event = Event::factory()->create(['tenant_id' => $tenant->id, 'requires_approval' => true]);
    $registration = EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'status' => EventRegistration::STATUS_PENDING_APPROVAL,
        'amount' => 0,
        'ticket_code' => null,
        'qr_token' => null,
    ]);

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "acme.{$baseDomain}";

    $this->actingAs($user)->postJson("http://{$host}/events/{$event->id}/registrations/{$registration->id}/approve", [], ['HTTP_HOST' => $host])
        ->assertOk();

    $registration->refresh();
    expect($registration->status)->toBe(EventRegistration::STATUS_CONFIRMED);
    expect($registration->ticket_code)->not->toBeNull();

    Mail::assertQueued(EventRegistrationConfirmed::class);
});

test('approving a paid pending registration invites payment instead of confirming outright', function () {
    Mail::fake();
    [$tenant, $user] = approvalHost();
    $event = Event::factory()->create(['tenant_id' => $tenant->id, 'requires_approval' => true]);
    $registration = EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'status' => EventRegistration::STATUS_PENDING_APPROVAL,
        'amount' => 5000,
        'ticket_code' => null,
        'qr_token' => null,
    ]);

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "acme.{$baseDomain}";

    $this->actingAs($user)->postJson("http://{$host}/events/{$event->id}/registrations/{$registration->id}/approve", [], ['HTTP_HOST' => $host])
        ->assertOk();

    $registration->refresh();
    expect($registration->status)->toBe(EventRegistration::STATUS_PENDING_PAYMENT);
    expect($registration->ticket_code)->toBeNull();

    Mail::assertQueued(EventRegistrationPaymentInvite::class, fn ($mail): bool => $mail->reason === 'approved');
});

test('rejecting a pending registration records a note and emails the registrant', function () {
    Mail::fake();
    [$tenant, $user] = approvalHost();
    $event = Event::factory()->create(['tenant_id' => $tenant->id, 'requires_approval' => true]);
    $registration = EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'status' => EventRegistration::STATUS_PENDING_APPROVAL,
    ]);

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "acme.{$baseDomain}";

    $this->actingAs($user)->postJson("http://{$host}/events/{$event->id}/registrations/{$registration->id}/reject", [
        'note' => 'Event is at faculty capacity.',
    ], ['HTTP_HOST' => $host])->assertOk();

    $registration->refresh();
    expect($registration->status)->toBe(EventRegistration::STATUS_REJECTED);
    expect($registration->approval_note)->toBe('Event is at faculty capacity.');

    Mail::assertQueued(EventRegistrationRejected::class);
});

test('an already-decided registration cannot be approved again', function () {
    [$tenant, $user] = approvalHost();
    $event = Event::factory()->create(['tenant_id' => $tenant->id]);
    $registration = EventRegistration::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id, 'status' => EventRegistration::STATUS_CONFIRMED]);

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "acme.{$baseDomain}";

    $this->actingAs($user)->postJson("http://{$host}/events/{$event->id}/registrations/{$registration->id}/approve", [], ['HTTP_HOST' => $host])
        ->assertStatus(422);
});

test('registering when a ticket type is sold out waitlists instead of rejecting, with an incrementing position', function () {
    Mail::fake();
    [$tenant] = approvalHost();
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);
    $ticketType = EventTicketType::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id, 'capacity' => 1, 'is_active' => true, 'price' => 0]);
    EventRegistration::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id, 'ticket_type_id' => $ticketType->id, 'status' => EventRegistration::STATUS_CONFIRMED]);

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "acme.{$baseDomain}";

    $this->post("http://{$host}/e/{$event->slug}/register", [
        'full_name' => 'First Waitlister',
        'email' => 'first@example.com',
        'ticket_type_id' => $ticketType->id,
    ], ['HTTP_HOST' => $host]);

    $this->post("http://{$host}/e/{$event->slug}/register", [
        'full_name' => 'Second Waitlister',
        'email' => 'second@example.com',
        'ticket_type_id' => $ticketType->id,
    ], ['HTTP_HOST' => $host]);

    $first = EventRegistration::where('email', 'first@example.com')->firstOrFail();
    $second = EventRegistration::where('email', 'second@example.com')->firstOrFail();

    expect($first->status)->toBe(EventRegistration::STATUS_WAITLISTED);
    expect($first->waitlist_position)->toBe(1);
    expect($second->waitlist_position)->toBe(2);

    Mail::assertQueued(EventRegistrationWaitlisted::class, 2);
});

test('cancelling a confirmed registration promotes the next waitlisted person and closes the gap', function () {
    Mail::fake();
    [$tenant, $user] = approvalHost();
    $event = Event::factory()->create(['tenant_id' => $tenant->id]);
    $confirmed = EventRegistration::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id, 'status' => EventRegistration::STATUS_CONFIRMED, 'amount' => 0]);
    $waitlistedFirst = EventRegistration::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id, 'status' => EventRegistration::STATUS_WAITLISTED, 'waitlist_position' => 1, 'amount' => 0, 'ticket_code' => null, 'qr_token' => null]);
    $waitlistedSecond = EventRegistration::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id, 'status' => EventRegistration::STATUS_WAITLISTED, 'waitlist_position' => 2, 'amount' => 0, 'ticket_code' => null, 'qr_token' => null]);

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "acme.{$baseDomain}";

    $this->actingAs($user)->postJson("http://{$host}/events/{$event->id}/registrations/{$confirmed->id}/cancel", [], ['HTTP_HOST' => $host])
        ->assertOk();

    expect($confirmed->fresh()->status)->toBe(EventRegistration::STATUS_CANCELLED);
    expect($waitlistedFirst->fresh()->status)->toBe(EventRegistration::STATUS_CONFIRMED);
    expect($waitlistedFirst->fresh()->waitlist_position)->toBeNull();
    expect($waitlistedFirst->fresh()->ticket_code)->not->toBeNull();
    expect($waitlistedSecond->fresh()->waitlist_position)->toBe(1);

    Mail::assertQueued(EventRegistrationConfirmed::class, fn ($mail): bool => $mail->registration->id === $waitlistedFirst->id);
});

test('cancelling a waitlisted registration does not promote anyone', function () {
    Mail::fake();
    [$tenant, $user] = approvalHost();
    $event = Event::factory()->create(['tenant_id' => $tenant->id]);
    $waitlisted = EventRegistration::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id, 'status' => EventRegistration::STATUS_WAITLISTED, 'waitlist_position' => 1]);

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "acme.{$baseDomain}";

    $this->actingAs($user)->postJson("http://{$host}/events/{$event->id}/registrations/{$waitlisted->id}/cancel", [], ['HTTP_HOST' => $host])
        ->assertOk();

    expect($waitlisted->fresh()->status)->toBe(EventRegistration::STATUS_CANCELLED);
    Mail::assertNothingSent();
});

test('a host without update event permission cannot approve a registration', function () {
    [$tenant] = approvalHost();
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    setPermissionsTeamId($tenant->id);
    $tenant->users()->attach($user->id); // no role assigned

    $event = Event::factory()->create(['tenant_id' => $tenant->id]);
    $registration = EventRegistration::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id, 'status' => EventRegistration::STATUS_PENDING_APPROVAL]);

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "acme.{$baseDomain}";

    $this->actingAs($user)->postJson("http://{$host}/events/{$event->id}/registrations/{$registration->id}/approve", [], ['HTTP_HOST' => $host])
        ->assertForbidden();
});
