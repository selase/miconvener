<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Mail\Events\EventRegistrationVerifyEmail;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\EventRegistrationTransfer;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Spatie\Activitylog\Models\Activity;

/**
 * An attendee's history is keyed to their email. A mistyped or shared address
 * locks them out of it, and an organiser correcting the address is the only
 * way back -- so the correction has to exist, be restricted, and be recorded.
 */
beforeEach(function () {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
});

function editableRegistration(string $slug): array
{
    [$tenant, $user] = eventHost($slug);
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);
    $registration = EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'full_name' => 'Ama Serwa',
        'email' => 'ama@stme.org',
        'phone' => null,
        'status' => EventRegistration::STATUS_CONFIRMED,
    ]);

    return [$tenant, $user, $event, $registration, eventSubdomainHost($slug)];
}

test('an organiser can correct an attendee\'s name, email and phone', function () {
    [, $user, $event, $registration, $host] = editableRegistration('edit-ok');

    $this->actingAs($user)->patchJson(
        "http://{$host}/events/{$event->id}/registrations/{$registration->id}",
        ['full_name' => 'Ama Serwaa', 'email' => 'ama@stem.org', 'phone' => '+233201234234'],
        ['HTTP_HOST' => $host]
    )->assertOk();

    $registration->refresh();
    expect($registration->full_name)->toBe('Ama Serwaa')
        ->and($registration->email)->toBe('ama@stem.org')
        ->and($registration->phone)->toBe('+233201234234');
});

test('the correction is recorded with the address it replaced', function () {
    config(['activitylog.enabled' => true]);
    [, $user, $event, $registration, $host] = editableRegistration('edit-log');

    $this->actingAs($user)->patchJson(
        "http://{$host}/events/{$event->id}/registrations/{$registration->id}",
        ['full_name' => 'Ama Serwa', 'email' => 'ama@stem.org', 'phone' => null],
        ['HTTP_HOST' => $host]
    )->assertOk();

    $entry = Activity::query()
        ->where('subject_id', $registration->id)
        ->where('event', 'updated')
        ->first();

    expect($entry)->not->toBeNull()
        ->and($entry->properties['old']['email'])->toBe('ama@stme.org')
        ->and($entry->properties['attributes']['email'])->toBe('ama@stem.org');
});

test('an address that is not an address is refused, and nothing changes', function () {
    [, $user, $event, $registration, $host] = editableRegistration('edit-invalid');

    $this->actingAs($user)->patchJson(
        "http://{$host}/events/{$event->id}/registrations/{$registration->id}",
        ['full_name' => 'Ama Serwaa', 'email' => 'not-an-address'],
        ['HTTP_HOST' => $host]
    )->assertUnprocessable()->assertJsonValidationErrors('email');

    expect($registration->fresh()->email)->toBe('ama@stme.org');
});

test('someone without permission to update events cannot correct a registration', function () {
    [$tenant, , $event, $registration, $host] = editableRegistration('edit-denied');
    $outsider = User::factory()->create(['tenant_id' => $tenant->id]);
    $tenant->users()->attach($outsider->id);

    $this->actingAs($outsider)->patchJson(
        "http://{$host}/events/{$event->id}/registrations/{$registration->id}",
        ['full_name' => 'Someone Else', 'email' => 'someone@else.org'],
        ['HTTP_HOST' => $host]
    )->assertForbidden();

    expect($registration->fresh()->email)->toBe('ama@stme.org');
});

test('an organiser cannot reach another organiser\'s registration', function () {
    [, $user, $event, , $host] = editableRegistration('edit-mine');
    [, , , $theirs] = editableRegistration('edit-theirs');

    $this->actingAs($user)->patchJson(
        "http://{$host}/events/{$event->id}/registrations/{$theirs->id}",
        ['full_name' => 'Taken Over', 'email' => 'taken@over.org'],
        ['HTTP_HOST' => $host]
    )->assertNotFound();

    expect($theirs->fresh()->email)->toBe('ama@stme.org');
});

test('registering again with the address in different capitals is the same person', function () {
    Mail::fake();
    [$tenant] = eventHost('dup-case');
    $host = eventSubdomainHost('dup-case');
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);

    $this->post("http://{$host}/e/{$event->slug}/register", [
        'full_name' => 'Ama Serwaa', 'email' => 'Ama@Example.com',
    ], ['HTTP_HOST' => $host]);

    // The same person, typing their address the way a phone keyboard did it.
    // Two rows would mean two tickets, and a history that shows the event twice.
    $this->post("http://{$host}/e/{$event->slug}/register", [
        'full_name' => 'Ama Serwaa', 'email' => 'ama@example.com',
    ], ['HTTP_HOST' => $host]);

    expect(EventRegistration::where('event_id', $event->id)->count())->toBe(1);
});

test('correcting an unverified attendee\'s address sends the verification link to the new one', function () {
    Mail::fake();
    [, $user, $event, $registration, $host] = editableRegistration('edit-unverified');
    $registration->update(['email_verified_at' => null, 'ticket_code' => null]);

    $this->actingAs($user)->patchJson(
        "http://{$host}/events/{$event->id}/registrations/{$registration->id}",
        ['full_name' => 'Ama Serwa', 'email' => 'ama@stem.org', 'phone' => null],
        ['HTTP_HOST' => $host]
    )->assertOk();

    // Correcting the address is pointless if the link they need still sits in
    // the inbox that was wrong.
    Mail::assertQueued(EventRegistrationVerifyEmail::class, fn ($mail): bool => $mail->hasTo('ama@stem.org'));
});

test('correcting a verified attendee\'s address does not ask them to verify again', function () {
    Mail::fake();
    [, $user, $event, $registration, $host] = editableRegistration('edit-verified');
    $registration->update(['email_verified_at' => now()]);

    $this->actingAs($user)->patchJson(
        "http://{$host}/events/{$event->id}/registrations/{$registration->id}",
        ['full_name' => 'Ama Serwa', 'email' => 'ama@stem.org', 'phone' => null],
        ['HTTP_HOST' => $host]
    )->assertOk();

    Mail::assertNotQueued(EventRegistrationVerifyEmail::class);
});

test('correcting an address kills a transfer code sent to the old one', function () {
    Mail::fake();
    [$tenant, $user, $event, $registration, $host] = editableRegistration('edit-transfer');

    $transfer = EventRegistrationTransfer::create([
        'tenant_id' => $tenant->id,
        'registration_id' => $registration->id,
        'to_full_name' => 'Kwame Asare',
        'to_email' => 'kwame@example.com',
        'code_hash' => Hash::make('482913'),
        'expires_at' => now()->addMinutes(EventRegistrationTransfer::TTL_MINUTES),
    ]);

    $this->actingAs($user)->patchJson(
        "http://{$host}/events/{$event->id}/registrations/{$registration->id}",
        ['full_name' => 'Ama Serwa', 'email' => 'ama@stem.org', 'phone' => null],
        ['HTTP_HOST' => $host]
    )->assertOk();

    // The code went to an inbox that is no longer the holder's.
    expect($transfer->fresh()->consumed_at)->not->toBeNull();
});
