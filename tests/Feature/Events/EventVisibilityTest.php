<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\EventSession;
use App\Models\EventSpeaker;
use App\Models\Speaker;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;

/**
 * For a closed corporate event the speaker list is itself the confidential
 * part, and the public page rendered speakers, sessions and sponsors to anyone
 * holding the link before they had registered for anything.
 */
beforeEach(function () {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
});

function eventWithLineup(string $slug, string $visibility): array
{
    $tenant = Tenant::factory()->create(['slug' => $slug, 'isolation_mode' => 'shared']);
    $event = Event::factory()->published()->create([
        'tenant_id' => $tenant->id,
        'visibility' => $visibility,
    ]);

    $speaker = Speaker::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Dr Confidential']);
    EventSpeaker::create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'speaker_id' => $speaker->id,
        'portal_token' => 'tok-'.$slug,
    ]);

    EventSession::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'title' => 'Secret Roadmap Session',
    ]);

    return [$tenant, $event, $slug.'.'.mb_ltrim((string) config('session.domain'), '.')];
}

test('a public event shows its lineup to anyone with the link', function () {
    [$tenant, $event, $host] = eventWithLineup('vis-public', Event::VISIBILITY_PUBLIC);

    $props = $this->get("http://{$host}/e/{$event->slug}", ['HTTP_HOST' => $host])
        ->assertOk()
        ->viewData('page')['props'];

    expect($props['event']['speakers'])->not->toBeEmpty();
    expect($props['event']['sessions'])->not->toBeEmpty();
});

test('a private event withholds its lineup from an unregistered visitor', function () {
    [$tenant, $event, $host] = eventWithLineup('vis-private', Event::VISIBILITY_PRIVATE);

    $response = $this->get("http://{$host}/e/{$event->slug}", ['HTTP_HOST' => $host])->assertOk();
    $props = $response->viewData('page')['props'];

    // Registration still has to be possible, so the page itself stays reachable.
    expect($props['event']['name'])->toBe($event->name);
    expect($props['isPrivate'])->toBeTrue();

    expect($props['event']['speakers'])->toBeEmpty();
    expect($props['event']['sessions'])->toBeEmpty();
    expect($props['event']['sponsors'])->toBeEmpty();

    // And nothing leaks through the raw response either.
    $response->assertDontSee('Dr Confidential');
    $response->assertDontSee('Secret Roadmap Session');
});

test('a confirmed registration unlocks a private event', function () {
    [$tenant, $event, $host] = eventWithLineup('vis-unlocked', Event::VISIBILITY_PRIVATE);

    $registration = EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'status' => EventRegistration::STATUS_CONFIRMED,
    ]);

    $props = $this->get("http://{$host}/e/{$event->slug}/registrations/{$registration->id}", ['HTTP_HOST' => $host])
        ->assertOk()
        ->viewData('page')['props'];

    expect($props['event']['sessions'])->not->toBeEmpty();
});

test('an unconfirmed registration does not unlock a private event', function () {
    [$tenant, $event, $host] = eventWithLineup('vis-pending', Event::VISIBILITY_PRIVATE);

    // Someone mid-checkout has not earned the lineup yet.
    $registration = EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'status' => EventRegistration::STATUS_PENDING_PAYMENT,
    ]);

    $props = $this->get("http://{$host}/e/{$event->slug}/registrations/{$registration->id}", ['HTTP_HOST' => $host])
        ->assertOk()
        ->viewData('page')['props'];

    expect($props['event']['sessions'])->toBeEmpty();
});

test('a private event has no public forum', function () {
    [$tenant, $event, $host] = eventWithLineup('vis-forum', Event::VISIBILITY_PRIVATE);

    // Who is asking what, under their own names, is as revealing as the lineup.
    $this->getJson("http://{$host}/e/{$event->slug}/forum", ['HTTP_HOST' => $host])->assertNotFound();
});

test('a host can flip an event private and it takes effect immediately', function () {
    [$tenant, $event, $host] = eventWithLineup('vis-toggle', Event::VISIBILITY_PUBLIC);

    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    setPermissionsTeamId($tenant->id);
    $user->assignRole('Org Superadmin');
    $tenant->users()->attach($user->id);

    $this->actingAs($user)
        ->patchJson("http://{$host}/events/{$event->id}/visibility", [
            'visibility' => Event::VISIBILITY_PRIVATE,
        ], ['HTTP_HOST' => $host])
        ->assertOk()
        ->assertJson(['visibility' => 'private']);

    // The public page reflects it without any further save.
    $props = $this->get("http://{$host}/e/{$event->slug}", ['HTTP_HOST' => $host])
        ->assertOk()
        ->viewData('page')['props'];

    expect($props['event']['speakers'])->toBeEmpty();
});

test('visibility only accepts the modes that exist', function () {
    [$tenant, $event, $host] = eventWithLineup('vis-invalid', Event::VISIBILITY_PUBLIC);

    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    setPermissionsTeamId($tenant->id);
    $user->assignRole('Org Superadmin');
    $tenant->users()->attach($user->id);

    // "unlisted" would behave identically to public here, so it is deliberately
    // not a mode.
    $this->actingAs($user)
        ->patchJson("http://{$host}/events/{$event->id}/visibility", [
            'visibility' => 'unlisted',
        ], ['HTTP_HOST' => $host])
        ->assertStatus(422);

    expect($event->fresh()->visibility)->toBe(Event::VISIBILITY_PUBLIC);
});

test('events are public unless someone says otherwise', function () {
    $tenant = Tenant::factory()->create(['slug' => 'vis-default', 'isolation_mode' => 'shared']);
    $event = Event::factory()->create(['tenant_id' => $tenant->id]);

    expect($event->visibility)->toBe(Event::VISIBILITY_PUBLIC);
    expect($event->isPrivate())->toBeFalse();
});
