<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Jobs\Events\SendEventBlastJob;
use App\Mail\Events\EventBlastMail;
use App\Models\Event;
use App\Models\EventBlast;
use App\Models\EventBlastRecipient;
use App\Models\EventRegistration;
use App\Models\EventSession;
use App\Models\EventTicketType;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

beforeEach(function () {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
});

function blastHost(): array
{
    $tenant = Tenant::factory()->create(['slug' => 'acme', 'isolation_mode' => 'shared']);
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    setPermissionsTeamId($tenant->id);
    $user->assignRole('Org Superadmin');
    $tenant->users()->attach($user->id);

    return [$tenant, $user];
}

test('host can send a blast to confirmed registrants', function () {
    Bus::fake();

    $tenant = Tenant::factory()->create(['slug' => 'acme', 'isolation_mode' => 'shared']);
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    setPermissionsTeamId($tenant->id);
    $user->assignRole('Org Superadmin');
    $tenant->users()->attach($user->id);

    $event = Event::factory()->create(['tenant_id' => $tenant->id]);
    EventRegistration::factory()->count(3)->create(['tenant_id' => $tenant->id, 'event_id' => $event->id, 'status' => EventRegistration::STATUS_CONFIRMED]);
    EventRegistration::factory()->pendingPayment()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id]);

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "acme.{$baseDomain}";

    $response = $this->actingAs($user)->postJson("http://{$host}/events/{$event->id}/blasts", [
        'subject' => 'See you soon',
        'body' => 'Looking forward to it.',
        'audience' => 'all',
    ], ['HTTP_HOST' => $host]);

    $response->assertOk();
    expect($response->json('recipients_count'))->toBe(3);

    Bus::assertDispatched(SendEventBlastJob::class);
});

test('the blast job only emails registrations in the selected audience', function () {
    Mail::fake();

    $tenant = Tenant::factory()->create(['slug' => 'acme', 'isolation_mode' => 'shared']);
    $event = Event::factory()->create(['tenant_id' => $tenant->id]);

    $confirmed = EventRegistration::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id, 'status' => EventRegistration::STATUS_CONFIRMED]);
    $checkedIn = EventRegistration::factory()->checkedIn()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id]);
    EventRegistration::factory()->pendingPayment()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id]);

    $blast = $event->blasts()->create([
        'tenant_id' => $tenant->id,
        'subject' => 'Checked-in only',
        'body' => 'Thanks for being here.',
        'audience' => 'checked_in',
        'recipients_count' => 1,
    ]);

    (new SendEventBlastJob($blast))->handle();

    Mail::assertQueued(EventBlastMail::class, fn ($mail): bool => $mail->hasTo($checkedIn->email));
    Mail::assertQueued(EventBlastMail::class, 1);
});

test('a blast scheduled for later is stored as scheduled and dispatched with a delay', function () {
    Bus::fake();
    [$tenant, $user] = blastHost();
    $event = Event::factory()->create(['tenant_id' => $tenant->id]);
    EventRegistration::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id, 'status' => EventRegistration::STATUS_CONFIRMED]);

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "acme.{$baseDomain}";
    $scheduledAt = now()->addDay()->startOfSecond();

    $response = $this->actingAs($user)->postJson("http://{$host}/events/{$event->id}/blasts", [
        'subject' => 'Reminder',
        'body' => 'See you tomorrow.',
        'audience' => 'all',
        'scheduled_at' => $scheduledAt->toIso8601String(),
    ], ['HTTP_HOST' => $host]);

    $response->assertOk();
    $blast = EventBlast::where('subject', 'Reminder')->firstOrFail();
    expect($blast->status)->toBe(EventBlast::STATUS_SCHEDULED);
    expect($blast->scheduled_at->equalTo($scheduledAt))->toBeTrue();

    Bus::assertDispatched(SendEventBlastJob::class, fn ($job): bool => $job->delay !== null);
});

test('a scheduled blast can be cancelled and the job then skips sending it', function () {
    Mail::fake();
    [$tenant, $user] = blastHost();
    $event = Event::factory()->create(['tenant_id' => $tenant->id]);
    EventRegistration::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id, 'status' => EventRegistration::STATUS_CONFIRMED]);

    $blast = $event->blasts()->create([
        'tenant_id' => $tenant->id,
        'subject' => 'Cancel me',
        'body' => 'Should never arrive.',
        'audience' => 'all',
        'recipients_count' => 1,
        'status' => EventBlast::STATUS_SCHEDULED,
        'scheduled_at' => now()->addDay(),
    ]);

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "acme.{$baseDomain}";

    $this->actingAs($user)->patchJson("http://{$host}/events/{$event->id}/blasts/{$blast->id}/cancel", [], ['HTTP_HOST' => $host])
        ->assertOk();

    expect($blast->fresh()->status)->toBe(EventBlast::STATUS_CANCELLED);

    (new SendEventBlastJob($blast->fresh()))->handle();

    Mail::assertNothingQueued();
});

test('an already-sent blast cannot be cancelled', function () {
    [$tenant, $user] = blastHost();
    $event = Event::factory()->create(['tenant_id' => $tenant->id]);

    $blast = $event->blasts()->create([
        'tenant_id' => $tenant->id,
        'subject' => 'Already sent',
        'body' => 'Too late.',
        'audience' => 'all',
        'recipients_count' => 1,
        'status' => EventBlast::STATUS_SENT,
    ]);

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "acme.{$baseDomain}";

    $this->actingAs($user)->patchJson("http://{$host}/events/{$event->id}/blasts/{$blast->id}/cancel", [], ['HTTP_HOST' => $host])
        ->assertStatus(422);
});

test('a blast can target people with a specific session in their agenda', function () {
    Mail::fake();
    [$tenant] = blastHost();
    $event = Event::factory()->create(['tenant_id' => $tenant->id]);
    $session = EventSession::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id]);

    $inAgenda = EventRegistration::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id, 'status' => EventRegistration::STATUS_CONFIRMED]);
    $inAgenda->sessions()->attach($session->id, ['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id]);
    EventRegistration::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id, 'status' => EventRegistration::STATUS_CONFIRMED]);

    $blast = $event->blasts()->create([
        'tenant_id' => $tenant->id,
        'subject' => 'Workshop reminder',
        'body' => 'See you there.',
        'audience' => "session:{$session->id}",
        'recipients_count' => 1,
    ]);

    (new SendEventBlastJob($blast))->handle();

    Mail::assertQueued(EventBlastMail::class, fn ($mail): bool => $mail->hasTo($inAgenda->email));
    Mail::assertQueued(EventBlastMail::class, 1);
});

test('a blast to a ticket type from a different event is rejected', function () {
    [$tenant, $user] = blastHost();
    $event = Event::factory()->create(['tenant_id' => $tenant->id]);
    $otherEvent = Event::factory()->create(['tenant_id' => $tenant->id]);
    $foreignTicketType = EventTicketType::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $otherEvent->id]);

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "acme.{$baseDomain}";

    $this->actingAs($user)->postJson("http://{$host}/events/{$event->id}/blasts", [
        'subject' => 'Leak attempt',
        'body' => 'Should be rejected.',
        'audience' => "ticket_type:{$foreignTicketType->id}",
    ], ['HTTP_HOST' => $host])->assertStatus(422);
});

test('opening the tracking pixel marks the recipient as having opened the blast, once', function () {
    [$tenant] = blastHost();
    $event = Event::factory()->create(['tenant_id' => $tenant->id]);
    $registration = EventRegistration::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id]);
    $blast = $event->blasts()->create([
        'tenant_id' => $tenant->id,
        'subject' => 'Track me',
        'body' => 'Body.',
        'audience' => 'all',
        'recipients_count' => 1,
    ]);
    $recipient = EventBlastRecipient::create(['tenant_id' => $tenant->id, 'blast_id' => $blast->id, 'registration_id' => $registration->id]);

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "acme.{$baseDomain}";

    $response = $this->get("http://{$host}/blasts/{$recipient->id}/open.gif", ['HTTP_HOST' => $host]);

    $response->assertOk();
    $response->assertHeader('Content-Type', 'image/gif');
    $firstOpenedAt = $recipient->fresh()->opened_at;
    expect($firstOpenedAt)->not->toBeNull();

    $this->get("http://{$host}/blasts/{$recipient->id}/open.gif", ['HTTP_HOST' => $host]);
    expect($recipient->fresh()->opened_at->equalTo($firstOpenedAt))->toBeTrue();
});
