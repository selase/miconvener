<?php

declare(strict_types=1);

use App\Models\Event;
use Illuminate\Support\Facades\Artisan;

beforeEach(function () {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
});

/**
 * The update route validates the whole event, so every request needs a full
 * valid payload. Each test overrides a single key of this one.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function eventUpdatePayload(Event $event, array $overrides = []): array
{
    return array_merge([
        'name' => $event->name,
        'description' => 'A great event',
        'status' => 'published',
        'starts_at' => now()->addWeek()->toDateTimeString(),
        'ends_at' => now()->addWeek()->addHours(3)->toDateTimeString(),
        'timezone' => 'Africa/Accra',
        'location_type' => 'in_person',
        'address' => '123 Main St',
        'ticket_price' => 0,
        'currency' => 'GHS',
    ], $overrides);
}

test('a tenant admin can move the platform fee onto the attendee', function () {
    [$tenant, $user] = eventHost('acme');
    $host = eventSubdomainHost('acme');

    $event = Event::factory()->create([
        'tenant_id' => $tenant->id,
        'fee_bearer' => null,
        'currency' => 'GHS',
    ]);

    $this->actingAs($user)->put(
        "http://{$host}/events/{$event->id}",
        eventUpdatePayload($event, ['fee_bearer' => 'attendee']),
        ['HTTP_HOST' => $host]
    );

    expect($event->refresh()->fee_bearer)->toBe('attendee');
});

test('a tenant admin cannot set their own commission percentage', function () {
    [$tenant, $user] = eventHost('acme');
    $host = eventSubdomainHost('acme');

    $event = Event::factory()->create([
        'tenant_id' => $tenant->id,
        'platform_fee_percentage' => 2.0,
        'currency' => 'GHS',
    ]);

    $this->actingAs($user)->put(
        "http://{$host}/events/{$event->id}",
        eventUpdatePayload($event, ['platform_fee_percentage' => 0]),
        ['HTTP_HOST' => $host]
    );

    // A redirect proves nothing here — assert the commission is untouched.
    expect((float) $event->refresh()->platform_fee_percentage)->toEqual(2.0);
});

test('a tenant admin cannot set their own commission cap', function () {
    [$tenant, $user] = eventHost('acme');
    $host = eventSubdomainHost('acme');

    $event = Event::factory()->create([
        'tenant_id' => $tenant->id,
        'platform_fee_cap_amount' => 2000,
        'currency' => 'GHS',
    ]);

    $this->actingAs($user)->put(
        "http://{$host}/events/{$event->id}",
        eventUpdatePayload($event, ['platform_fee_cap_amount' => 1]),
        ['HTTP_HOST' => $host]
    );

    expect($event->refresh()->platform_fee_cap_amount)->toBe(2000);
});

test('an invalid fee bearer is rejected', function () {
    [$tenant, $user] = eventHost('acme');
    $host = eventSubdomainHost('acme');

    $event = Event::factory()->create(['tenant_id' => $tenant->id, 'currency' => 'GHS']);

    $this->actingAs($user)->put(
        "http://{$host}/events/{$event->id}",
        eventUpdatePayload($event, ['fee_bearer' => 'somebody_else']),
        ['HTTP_HOST' => $host]
    )->assertSessionHasErrors('fee_bearer');
});

test('the superadmin command sets a commission cap and clears it again', function () {
    [$tenant] = eventHost('acme');
    $event = Event::factory()->create([
        'tenant_id' => $tenant->id,
        'slug' => 'charity-gala',
        'platform_fee_cap_amount' => null,
        'currency' => 'GHS',
    ]);

    Artisan::call('events:set-platform-fee', [
        'tenant_slug' => $tenant->slug, 'percentage' => 2.0, '--event' => 'charity-gala', '--cap' => 500,
    ]);
    expect($event->refresh()->platform_fee_cap_amount)->toBe(500);

    Artisan::call('events:set-platform-fee', [
        'tenant_slug' => $tenant->slug, 'percentage' => 0, '--event' => 'charity-gala', '--clear' => true,
    ]);
    expect($event->refresh()->platform_fee_cap_amount)->toBeNull()
        ->and($event->refresh()->platform_fee_percentage)->toBeNull();
});

test('the event page shows the organizer what one ticket actually nets them', function () {
    [$tenant, $user] = eventHost('acme');
    $host = eventSubdomainHost('acme');

    $tenant->update(['platform_fee_percentage' => 2.0]);

    $event = Event::factory()->create([
        'tenant_id' => $tenant->id,
        'ticket_price' => 10000,
        'fee_bearer' => 'organizer',
        'currency' => 'GHS',
    ]);

    $this->actingAs($user)
        ->get("http://{$host}/events/{$event->id}", ['HTTP_HOST' => $host])
        ->assertInertia(fn ($page) => $page
            ->where('event.fee_preview.ticket_amount', 10000)
            ->where('event.fee_preview.charged_amount', 10000)
            ->where('event.fee_preview.platform_fee', 200)
            ->where('event.fee_preview.gateway_fee_estimate', 195)
            ->where('event.fee_preview.organizer_net', 9605)
        );
});

test('the preview shows a pass-through event charging the buyer more', function () {
    [$tenant, $user] = eventHost('acme');
    $host = eventSubdomainHost('acme');

    $tenant->update(['platform_fee_percentage' => 2.0]);

    $event = Event::factory()->create([
        'tenant_id' => $tenant->id,
        'ticket_price' => 10000,
        'fee_bearer' => 'attendee',
        'currency' => 'GHS',
    ]);

    $this->actingAs($user)
        ->get("http://{$host}/events/{$event->id}", ['HTTP_HOST' => $host])
        ->assertInertia(fn ($page) => $page
            ->where('event.fee_preview.charged_amount', 10200)
            ->where('event.fee_preview.organizer_net', 9801)
        );
});
