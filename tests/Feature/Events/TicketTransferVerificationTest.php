<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Mail\Events\EventTicketTransferCode;
use App\Mail\Events\EventTicketTransferred;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\EventRegistrationTransfer;
use App\Models\Tenant;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

/**
 * A transfer overwrites the holder's name and email irreversibly, and it used to
 * need nothing but the portal URL -- so anyone forwarded a confirmation email
 * could take the ticket without the owner ever knowing. These tests exist to
 * keep that shut.
 */
beforeEach(function () {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
});

function transferrableTicket(string $slug): array
{
    $tenant = Tenant::factory()->create(['slug' => $slug, 'isolation_mode' => 'shared']);
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);
    $registration = EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'full_name' => 'Ama Mensah',
        'email' => 'ama@example.com',
        'status' => EventRegistration::STATUS_CONFIRMED,
    ]);
    $registration->issueTicket();
    $registration->save();

    return [$tenant, $event, $registration, $slug.'.'.mb_ltrim((string) config('session.domain'), '.')];
}

test('requesting a transfer does not move the ticket, it only sends a code', function () {
    Mail::fake();
    [$tenant, $event, $registration, $host] = transferrableTicket('xfer-request');

    $this->postJson("http://{$host}/e/{$event->slug}/registrations/{$registration->id}/transfer", [
        'full_name' => 'Kwame Asare',
        'email' => 'kwame@example.com',
    ], ['HTTP_HOST' => $host])->assertOk();

    // The ticket is untouched until the code comes back.
    $registration->refresh();
    expect($registration->full_name)->toBe('Ama Mensah');
    expect($registration->email)->toBe('ama@example.com');

    // And the code goes to the CURRENT holder, never to the new address.
    Mail::assertQueued(EventTicketTransferCode::class, fn ($mail): bool => $mail->hasTo('ama@example.com'));
    Mail::assertNotQueued(EventTicketTransferCode::class, fn ($mail): bool => $mail->hasTo('kwame@example.com'));
});

test('a correct code completes the transfer and warns the previous holder', function () {
    Mail::fake();
    [$tenant, $event, $registration, $host] = transferrableTicket('xfer-ok');

    $this->postJson("http://{$host}/e/{$event->slug}/registrations/{$registration->id}/transfer", [
        'full_name' => 'Kwame Asare',
        'email' => 'kwame@example.com',
    ], ['HTTP_HOST' => $host])->assertOk();

    // Read the code the way the holder would -- out of the email.
    $code = null;
    Mail::assertQueued(EventTicketTransferCode::class, function ($mail) use (&$code): bool {
        $code = $mail->transfer->plainCode;

        return true;
    });
    expect($code)->not->toBeNull();

    $this->postJson("http://{$host}/e/{$event->slug}/registrations/{$registration->id}/transfer/confirm", [
        'code' => $code,
    ], ['HTTP_HOST' => $host])->assertOk();

    $registration->refresh();
    expect($registration->full_name)->toBe('Kwame Asare');
    expect($registration->email)->toBe('kwame@example.com');

    Mail::assertQueued(EventTicketTransferred::class, fn ($mail): bool => $mail->hasTo('ama@example.com'));
});

test('a wrong code does not move the ticket and is counted', function () {
    Mail::fake();
    [$tenant, $event, $registration, $host] = transferrableTicket('xfer-wrong');

    $this->postJson("http://{$host}/e/{$event->slug}/registrations/{$registration->id}/transfer", [
        'full_name' => 'Kwame Asare',
        'email' => 'kwame@example.com',
    ], ['HTTP_HOST' => $host])->assertOk();

    $this->postJson("http://{$host}/e/{$event->slug}/registrations/{$registration->id}/transfer/confirm", [
        'code' => '000000',
    ], ['HTTP_HOST' => $host])->assertStatus(422);

    $registration->refresh();
    expect($registration->email)->toBe('ama@example.com');
    expect(EventRegistrationTransfer::first()->attempts)->toBe(1);
});

test('guessing is capped', function () {
    Mail::fake();
    [$tenant, $event, $registration, $host] = transferrableTicket('xfer-brute');

    $this->postJson("http://{$host}/e/{$event->slug}/registrations/{$registration->id}/transfer", [
        'full_name' => 'Kwame Asare', 'email' => 'kwame@example.com',
    ], ['HTTP_HOST' => $host])->assertOk();

    foreach (range(1, EventRegistrationTransfer::MAX_ATTEMPTS) as $i) {
        $this->postJson("http://{$host}/e/{$event->slug}/registrations/{$registration->id}/transfer/confirm",
            ['code' => '000000'], ['HTTP_HOST' => $host])->assertStatus(422);
    }

    // Even the real code is refused once the cap is hit.
    $transfer = EventRegistrationTransfer::first();
    $transfer->update(['code_hash' => Hash::make('123456')]);

    $this->postJson("http://{$host}/e/{$event->slug}/registrations/{$registration->id}/transfer/confirm",
        ['code' => '123456'], ['HTTP_HOST' => $host])->assertStatus(422);

    $registration->refresh();
    expect($registration->email)->toBe('ama@example.com');
});

test('an expired code is refused', function () {
    Mail::fake();
    [$tenant, $event, $registration, $host] = transferrableTicket('xfer-expired');

    $this->postJson("http://{$host}/e/{$event->slug}/registrations/{$registration->id}/transfer", [
        'full_name' => 'Kwame Asare', 'email' => 'kwame@example.com',
    ], ['HTTP_HOST' => $host])->assertOk();

    $code = null;
    Mail::assertQueued(EventTicketTransferCode::class, function ($mail) use (&$code): bool {
        $code = $mail->transfer->plainCode;

        return true;
    });

    EventRegistrationTransfer::first()->update(['expires_at' => now()->subMinute()]);

    $this->postJson("http://{$host}/e/{$event->slug}/registrations/{$registration->id}/transfer/confirm",
        ['code' => $code], ['HTTP_HOST' => $host])->assertStatus(422);

    $registration->refresh();
    expect($registration->email)->toBe('ama@example.com');
});

test('a checked-in ticket cannot be transferred even with a valid code', function () {
    Mail::fake();
    [$tenant, $event, $registration, $host] = transferrableTicket('xfer-checkedin');

    $this->postJson("http://{$host}/e/{$event->slug}/registrations/{$registration->id}/transfer", [
        'full_name' => 'Kwame Asare', 'email' => 'kwame@example.com',
    ], ['HTTP_HOST' => $host])->assertOk();

    $code = null;
    Mail::assertQueued(EventTicketTransferCode::class, function ($mail) use (&$code): bool {
        $code = $mail->transfer->plainCode;

        return true;
    });

    // Scanned in while the code sat in an inbox.
    $registration->update(['status' => EventRegistration::STATUS_CHECKED_IN]);

    $this->postJson("http://{$host}/e/{$event->slug}/registrations/{$registration->id}/transfer/confirm",
        ['code' => $code], ['HTTP_HOST' => $host])->assertStatus(422);

    $registration->refresh();
    expect($registration->email)->toBe('ama@example.com');
});

test('a fresh request supersedes an abandoned one', function () {
    Mail::fake();
    [$tenant, $event, $registration, $host] = transferrableTicket('xfer-super');

    $codes = [];
    foreach (['first@example.com', 'second@example.com'] as $target) {
        $this->postJson("http://{$host}/e/{$event->slug}/registrations/{$registration->id}/transfer", [
            'full_name' => 'Kwame Asare', 'email' => $target,
        ], ['HTTP_HOST' => $host])->assertOk();
    }

    Mail::assertQueued(EventTicketTransferCode::class, function ($mail) use (&$codes): bool {
        $codes[] = $mail->transfer->plainCode;

        return true;
    });

    // The first code must be dead, or an abandoned attempt stays exploitable.
    $this->postJson("http://{$host}/e/{$event->slug}/registrations/{$registration->id}/transfer/confirm",
        ['code' => $codes[0]], ['HTTP_HOST' => $host])->assertStatus(422);

    $registration->refresh();
    expect($registration->email)->toBe('ama@example.com');
});
