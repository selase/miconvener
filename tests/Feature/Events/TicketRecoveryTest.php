<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Mail\Events\EventTicketLink;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\Tenant;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;

/**
 * A ticket lives behind a link in an email, so losing the email loses the
 * ticket. Recovery must not become a way to discover who is attending -- for a
 * private event, that list is the guest list.
 */
beforeEach(function () {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
});

function recoverableEvent(string $slug): array
{
    $tenant = Tenant::factory()->create(['slug' => $slug, 'isolation_mode' => 'shared']);
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);

    return [$tenant, $event, $slug.'.'.mb_ltrim((string) config('session.domain'), '.')];
}

test('a registered attendee is emailed their ticket link again', function () {
    Mail::fake();
    [$tenant, $event, $host] = recoverableEvent('rec-found');

    EventRegistration::factory()->create([
        'tenant_id' => $tenant->id, 'event_id' => $event->id,
        'email' => 'ama@example.com', 'status' => EventRegistration::STATUS_CONFIRMED,
    ]);

    $this->postJson("http://{$host}/e/{$event->slug}/find-ticket", [
        'email' => 'ama@example.com',
    ], ['HTTP_HOST' => $host])->assertOk();

    Mail::assertQueued(EventTicketLink::class, fn ($mail): bool => $mail->hasTo('ama@example.com'));
});

test('an unknown address gets the same answer and no email', function () {
    Mail::fake();
    [$tenant, $event, $host] = recoverableEvent('rec-unknown');

    EventRegistration::factory()->create([
        'tenant_id' => $tenant->id, 'event_id' => $event->id,
        'email' => 'ama@example.com', 'status' => EventRegistration::STATUS_CONFIRMED,
    ]);

    $known = $this->postJson("http://{$host}/e/{$event->slug}/find-ticket",
        ['email' => 'ama@example.com'], ['HTTP_HOST' => $host])->assertOk();

    $unknown = $this->postJson("http://{$host}/e/{$event->slug}/find-ticket",
        ['email' => 'stranger@example.com'], ['HTTP_HOST' => $host])->assertOk();

    // Identical responses, or this endpoint answers "is this person attending?".
    expect($unknown->json('message'))->toBe($known->json('message'));

    Mail::assertNotQueued(EventTicketLink::class, fn ($mail): bool => $mail->hasTo('stranger@example.com'));
});

test('a cancelled registration is not recoverable', function () {
    Mail::fake();
    [$tenant, $event, $host] = recoverableEvent('rec-cancelled');

    EventRegistration::factory()->create([
        'tenant_id' => $tenant->id, 'event_id' => $event->id,
        'email' => 'ama@example.com', 'status' => EventRegistration::STATUS_CANCELLED,
    ]);

    $this->postJson("http://{$host}/e/{$event->slug}/find-ticket",
        ['email' => 'ama@example.com'], ['HTTP_HOST' => $host])->assertOk();

    Mail::assertNothingQueued();
});

test('recovery works for a private event without revealing anything about it', function () {
    Mail::fake();
    $tenant = Tenant::factory()->create(['slug' => 'rec-private', 'isolation_mode' => 'shared']);
    $event = Event::factory()->published()->create([
        'tenant_id' => $tenant->id,
        'visibility' => Event::VISIBILITY_PRIVATE,
    ]);
    EventRegistration::factory()->create([
        'tenant_id' => $tenant->id, 'event_id' => $event->id,
        'email' => 'ama@example.com', 'status' => EventRegistration::STATUS_CONFIRMED,
    ]);
    $host = 'rec-private.'.mb_ltrim((string) config('session.domain'), '.');

    $this->postJson("http://{$host}/e/{$event->slug}/find-ticket",
        ['email' => 'ama@example.com'], ['HTTP_HOST' => $host])->assertOk();

    Mail::assertQueued(EventTicketLink::class);
});

test('recovery is rate limited', function () {
    Mail::fake();
    [$tenant, $event, $host] = recoverableEvent('rec-throttle');

    $statuses = [];
    foreach (range(1, 14) as $i) {
        $statuses[] = $this->postJson("http://{$host}/e/{$event->slug}/find-ticket",
            ['email' => "probe{$i}@example.com"], ['HTTP_HOST' => $host])->getStatusCode();
    }

    // Without a limit this is a tool for walking an address list.
    expect($statuses)->toContain(429);
});
