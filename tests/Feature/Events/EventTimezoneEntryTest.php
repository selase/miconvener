<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Models\Event;
use Illuminate\Support\Facades\Artisan;

beforeEach(function (): void {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
    [$this->tenant, $this->user] = eventHost('acme');
    $this->host = eventSubdomainHost('acme');
});

function eventPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Maryland Gathering',
        'status' => Event::STATUS_PUBLISHED,
        'timezone' => 'America/New_York',
        'location_type' => 'in_person',
        'address' => 'Gaithersburg, Maryland',
        'capacity' => 30,
        'ticket_price' => 0,
        'currency' => 'USD',
    ], $overrides);
}

test('a time typed for an event is its own timezone, not UTC', function (): void {
    $this->actingAs($this->user)->post("http://{$this->host}/events", eventPayload([
        'starts_at' => '2026-11-28T15:00',
        'ends_at' => '2026-11-28T19:00',
    ]), ['HTTP_HOST' => $this->host])->assertRedirect();

    $event = Event::query()->where('name', 'Maryland Gathering')->sole();

    expect($event->starts_at->utc()->format('Y-m-d H:i'))->toBe('2026-11-28 20:00')
        ->and($event->ends_at->utc()->format('Y-m-d H:i'))->toBe('2026-11-29 00:00');
});

test('editing an event keeps its time in the event timezone', function (): void {
    $event = Event::factory()->create([
        'tenant_id' => $this->tenant->id,
        'timezone' => 'America/New_York',
        'starts_at' => '2026-11-28 20:00:00',
        'ends_at' => '2026-11-29 00:00:00',
    ]);

    $this->actingAs($this->user)->put("http://{$this->host}/events/{$event->id}", eventPayload([
        'name' => $event->name,
        'starts_at' => '2026-12-05T15:00',
        'ends_at' => '2026-12-05T19:00',
    ]), ['HTTP_HOST' => $this->host])->assertRedirect();

    expect($event->fresh()->starts_at->utc()->format('Y-m-d H:i'))->toBe('2026-12-05 20:00');
});

test('a time that already names an offset is taken as that exact moment', function (): void {
    $this->actingAs($this->user)->post("http://{$this->host}/events", eventPayload([
        'starts_at' => '2026-11-28T20:00:00+00:00',
        'ends_at' => '2026-11-29T00:00:00+00:00',
    ]), ['HTTP_HOST' => $this->host])->assertRedirect();

    expect(Event::query()->where('name', 'Maryland Gathering')->sole()->starts_at->utc()->format('Y-m-d H:i'))->toBe('2026-11-28 20:00');
});

test('an Accra event is unchanged, since Accra is UTC', function (): void {
    $this->actingAs($this->user)->post("http://{$this->host}/events", eventPayload([
        'timezone' => 'Africa/Accra',
        'currency' => 'GHS',
        'starts_at' => '2026-11-28T15:00',
        'ends_at' => '2026-11-28T19:00',
    ]), ['HTTP_HOST' => $this->host])->assertRedirect();

    expect(Event::query()->where('name', 'Maryland Gathering')->sole()->starts_at->utc()->format('H:i'))->toBe('15:00');
});
