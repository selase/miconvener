<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Models\Event;
use App\Models\EventMaterial;
use App\Models\EventSession;
use App\Models\Tenant;
use Database\Seeders\ProbeWorkspaceSeeder;
use DateTimeInterface;

/**
 * The demo event is meant to be running whenever someone opens it. Rather
 * than re-seeding before every demo (which resets decks and progress), an
 * hourly command moves the event forward in time when its window is about
 * to close, keeping the spacing of its sessions and material releases.
 */
beforeEach(function (): void {
    refreshTenantDatabases();
    config()->set('attendee_portal.probe.tenant_slug', 'demo-tenant');
    $this->tenant = Tenant::factory()->create(['slug' => 'demo-tenant', 'isolation_mode' => 'shared']);
});

function demoEvent(Tenant $tenant, DateTimeInterface $startsAt, DateTimeInterface $endsAt): Event
{
    return Event::factory()->published()->create([
        'tenant_id' => $tenant->id,
        'slug' => ProbeWorkspaceSeeder::EVENT_SLUG,
        'starts_at' => $startsAt,
        'ends_at' => $endsAt,
    ]);
}

test('a demo event about to end is moved forward, with its sessions and releases', function (): void {
    $this->freezeSecond();
    $event = demoEvent($this->tenant, now()->subHours(7), now()->addHour());
    $session = EventSession::factory()->create([
        'tenant_id' => $this->tenant->id,
        'event_id' => $event->id,
        'starts_at' => now()->subHours(6),
        'ends_at' => now()->subHours(5),
    ]);
    $material = EventMaterial::factory()->create([
        'tenant_id' => $this->tenant->id,
        'event_id' => $event->id,
        'release_at' => now()->addHours(2),
    ]);

    $this->artisan('demo:keep-live')->assertSuccessful();

    // Moved by 5 hours: starts two hours ago again, ends six hours ahead.
    expect($event->fresh()->starts_at->equalTo(now()->subHours(2)))->toBeTrue()
        ->and($event->fresh()->ends_at->equalTo(now()->addHours(6)))->toBeTrue()
        ->and($session->fresh()->starts_at->equalTo(now()->subHour()))->toBeTrue()
        ->and($material->fresh()->release_at->equalTo(now()->addHours(7)))->toBeTrue();
});

test('a demo event with plenty of time left is not touched', function (): void {
    $this->freezeSecond();
    $event = demoEvent($this->tenant, now()->subHour(), now()->addHours(5));

    $this->artisan('demo:keep-live')->assertSuccessful();

    expect($event->fresh()->starts_at->equalTo(now()->subHour()))->toBeTrue();
});

test('another organisation\'s event with the same slug is never moved', function (): void {
    $this->freezeSecond();
    $other = Tenant::factory()->create(['slug' => 'someone-else', 'isolation_mode' => 'shared']);
    $event = demoEvent($other, now()->subHours(7), now()->addHour());

    $this->artisan('demo:keep-live')->assertSuccessful();

    expect($event->fresh()->starts_at->equalTo(now()->subHours(7)))->toBeTrue();
});

test('the command runs every hour', function (): void {
    $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
        ->filter(fn ($event): bool => str_contains((string) $event->command, 'demo:keep-live'));

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('0 * * * *');
});
