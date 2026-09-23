<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\Tenant;
use App\Services\Events\PlatformAttendeeVerification;
use App\Services\Tenancy\TenantHostMatcher;
use Illuminate\Support\Facades\Artisan;

beforeEach(function (): void {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
});

function dashboardPlatformHost(): string
{
    return app(TenantHostMatcher::class)->baseDomain();
}

function dashboardAttendeeSession(string $email): array
{
    return [
        PlatformAttendeeVerification::SESSION_KEY => [
            'email' => PlatformAttendeeVerification::normalise($email),
            'verified_at' => now()->getTimestamp(),
            'expires_at' => now()->addHours(12)->getTimestamp(),
        ],
    ];
}

test('dashboard page renders for verified attendee with preloaded lifecycle history', function (): void {
    $host = dashboardPlatformHost();
    $tenant = Tenant::factory()->create(['name' => 'Tech Org', 'slug' => 'tech-org', 'isolation_mode' => 'shared']);
    $email = 'attendee@example.com';

    // 1. Unpaid registration -> needs_attention
    $unpaidEvent = Event::factory()->published()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Unpaid Conf',
        'starts_at' => now()->addDays(5),
        'ends_at' => now()->addDays(6),
    ]);
    EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $unpaidEvent->id,
        'email' => $email,
        'status' => EventRegistration::STATUS_PENDING_PAYMENT,
    ]);

    // 2. Live event -> live_now
    $liveEvent = Event::factory()->published()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Live Summit',
        'starts_at' => now()->subHours(2),
        'ends_at' => now()->addHours(4),
    ]);
    EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $liveEvent->id,
        'email' => $email,
        'status' => EventRegistration::STATUS_CONFIRMED,
        'ticket_code' => 'LIVE-999',
    ]);

    // 3. Upcoming event -> organisers.upcoming
    $upcomingEvent = Event::factory()->published()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Future Expo',
        'starts_at' => now()->addMonths(2),
        'ends_at' => now()->addMonths(2)->addDays(2),
    ]);
    EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $upcomingEvent->id,
        'email' => $email,
        'status' => EventRegistration::STATUS_CONFIRMED,
        'ticket_code' => 'UP-123',
    ]);

    // 4. Past event -> organisers.past
    $pastEvent = Event::factory()->published()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Past Gala',
        'starts_at' => now()->subMonths(1),
        'ends_at' => now()->subMonths(1)->addDays(1),
    ]);
    EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $pastEvent->id,
        'email' => $email,
        'status' => EventRegistration::STATUS_CONFIRMED,
        'ticket_code' => 'PAST-001',
    ]);

    $this->withSession(dashboardAttendeeSession($email))
        ->get("http://{$host}/my", ['HTTP_HOST' => $host])
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Public/Events/AttendeePortal/MyPortal')
            ->where('verifiedEmail', 'at••••••@example.com')
            ->has('initialHistory.needs_attention', 1)
            ->where('initialHistory.needs_attention.0.reason', 'Payment required to secure your ticket')
            ->has('initialHistory.live_now', 1)
            ->where('initialHistory.live_now.0.ticket.code', 'LIVE-999')
            ->has('initialHistory.organisers', 1)
            ->where('initialHistory.organisers.0.name', 'Tech Org')
            ->has('initialHistory.organisers.0.upcoming', 3) // Unpaid + Live + Upcoming
            ->has('initialHistory.organisers.0.past', 1) // Past
        );
});

test('dashboard page scopes initial history when organiser query parameter is provided', function (): void {
    $host = dashboardPlatformHost();
    $email = 'attendee@example.com';

    $tenantA = Tenant::factory()->create(['name' => 'Org Alpha', 'slug' => 'org-alpha', 'isolation_mode' => 'shared']);
    $tenantB = Tenant::factory()->create(['name' => 'Org Beta', 'slug' => 'org-beta', 'isolation_mode' => 'shared']);

    $eventA = Event::factory()->published()->create([
        'tenant_id' => $tenantA->id,
        'name' => 'Alpha Event',
        'starts_at' => now()->addDays(10),
    ]);
    EventRegistration::factory()->create([
        'tenant_id' => $tenantA->id,
        'event_id' => $eventA->id,
        'email' => $email,
        'status' => EventRegistration::STATUS_CONFIRMED,
    ]);

    $eventB = Event::factory()->published()->create([
        'tenant_id' => $tenantB->id,
        'name' => 'Beta Event',
        'starts_at' => now()->addDays(20),
    ]);
    EventRegistration::factory()->create([
        'tenant_id' => $tenantB->id,
        'event_id' => $eventB->id,
        'email' => $email,
        'status' => EventRegistration::STATUS_CONFIRMED,
    ]);

    // Request with organiser=org-alpha
    $this->withSession(dashboardAttendeeSession($email))
        ->get("http://{$host}/my?organiser=org-alpha", ['HTTP_HOST' => $host])
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Public/Events/AttendeePortal/MyPortal')
            ->where('organiser.name', 'Org Alpha')
            ->where('organiser.slug', 'org-alpha')
            ->has('initialHistory.organisers', 1)
            ->where('initialHistory.organisers.0.slug', 'org-alpha')
        );
});

test('unverified attendee visiting /my receives null verifiedEmail and null initialHistory', function (): void {
    $host = dashboardPlatformHost();

    $this->get("http://{$host}/my", ['HTTP_HOST' => $host])
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Public/Events/AttendeePortal/MyPortal')
            ->where('verifiedEmail', null)
            ->where('initialHistory', null)
        );
});
