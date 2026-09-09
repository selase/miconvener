<?php

declare(strict_types=1);

namespace Tests\Feature\Tenant;

use App\Models\Event;
use App\Models\EventLedgerEntry;
use App\Models\EventRegistration;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;

/**
 * The dashboard used to be a three-item setup checklist that never changed once
 * completed. These assert it now answers the questions an organizer opens the
 * app for, from real data.
 */
beforeEach(function () {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
});

function dashboardActor(string $slug): array
{
    $tenant = Tenant::factory()->create(['slug' => $slug, 'isolation_mode' => 'shared']);
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    setPermissionsTeamId($tenant->id);
    $user->assignRole('Org Superadmin');
    $tenant->users()->attach($user->id);

    return [$tenant, $user, $slug.'.'.mb_ltrim((string) config('session.domain'), '.')];
}

test('tenant dashboard renders the Inertia component with tenant and checklist data', function () {
    $user = User::factory()->create();
    $tenant = setActiveTenantForTest($user, ['onboarding_completed_at' => null]);

    $this->actingAs($user)
        ->get(route('tenant.dashboard', ['subdomain' => $tenant->slug]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Tenant/Dashboard')
            ->where('tenant.name', $tenant->name)
            ->where('checklist.onboarding', false)
            ->where('checklist.team', false)
            ->has('links.branding')
            ->has('links.team')
            ->has('links.finishOnboarding')
        );
});

test('tenant dashboard checklist reflects a completed onboarding state', function () {
    $user = User::factory()->create();
    $tenant = setActiveTenantForTest($user, ['onboarding_completed_at' => now()]);

    $this->actingAs($user)
        ->get(route('tenant.dashboard', ['subdomain' => $tenant->slug]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Tenant/Dashboard')
            ->where('checklist.onboarding', true)
        );
});

test('guests are redirected away from the tenant dashboard', function () {
    $tenant = setActiveTenantForTest();

    $this->get(route('tenant.dashboard', ['subdomain' => $tenant->slug]))
        ->assertRedirect(route('login'));
});

test('the dashboard reports the next event and its real registration counts', function () {
    [$tenant, $user, $host] = dashboardActor('dash-next');

    $event = Event::factory()->published()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Accra Tech Summit',
        'capacity' => 100,
        'starts_at' => now()->addDays(10),
        'ends_at' => now()->addDays(10)->addHours(6),
    ]);

    EventRegistration::factory()->count(3)->create([
        'tenant_id' => $tenant->id, 'event_id' => $event->id,
        'status' => EventRegistration::STATUS_CONFIRMED,
    ]);
    EventRegistration::factory()->count(2)->create([
        'tenant_id' => $tenant->id, 'event_id' => $event->id,
        'status' => EventRegistration::STATUS_PENDING_PAYMENT,
    ]);

    $props = $this->actingAs($user)
        ->get("http://{$host}/dashboard", ['HTTP_HOST' => $host])
        ->assertOk()
        ->viewData('page')['props'];

    expect($props['liveEvent'])->toBeNull();
    expect($props['nextEvent']['name'])->toBe('Accra Tech Summit');
    expect($props['nextEvent']['registered'])->toBe(5);
    expect($props['nextEvent']['confirmed'])->toBe(3);
    expect($props['nextEvent']['awaiting_payment'])->toBe(2);

    // Tenant-wide totals, not this one event's.
    expect($props['totals']['upcoming_events'])->toBe(1);
    expect($props['totals']['registrations'])->toBe(3);

    // The event is reachable directly from the list.
    expect($props['events'])->toHaveCount(1);
    expect($props['events'][0]['name'])->toBe('Accra Tech Summit');
    expect($props['events'][0]['url'])->toContain($event->id);
});

test('an event happening right now takes over the dashboard', function () {
    [$tenant, $user, $host] = dashboardActor('dash-live');

    // A live event and a later one: the live one must win.
    Event::factory()->published()->create([
        'tenant_id' => $tenant->id, 'name' => 'Later Event',
        'starts_at' => now()->addDays(5), 'ends_at' => now()->addDays(5)->addHours(3),
    ]);
    Event::factory()->published()->create([
        'tenant_id' => $tenant->id, 'name' => 'Happening Now',
        'starts_at' => now()->subHour(), 'ends_at' => now()->addHours(5),
    ]);

    $props = $this->actingAs($user)
        ->get("http://{$host}/dashboard", ['HTTP_HOST' => $host])
        ->assertOk()
        ->viewData('page')['props'];

    expect($props['liveEvent']['name'])->toBe('Happening Now');
    expect($props['nextEvent'])->toBeNull();

    // Both events remain reachable from the list even while one is running.
    expect(collect($props['events'])->pluck('name')->all())
        ->toContain('Happening Now', 'Later Event');
});

test('the money panel reads from the ledger, not from registrations', function () {
    [$tenant, $user, $host] = dashboardActor('dash-money');

    $event = Event::factory()->published()->create([
        'tenant_id' => $tenant->id,
        'starts_at' => now()->addDays(3), 'ends_at' => now()->addDays(3)->addHours(3),
    ]);

    EventLedgerEntry::create([
        'tenant_id' => $tenant->id, 'event_id' => $event->id,
        'type' => EventLedgerEntry::TYPE_CHARGE,
        'gross_amount' => 20000, 'gateway_fee_amount' => 390,
        'commission_amount' => 400, 'net_amount' => 19210,
        'currency' => 'GHS', 'provider' => 'paystack', 'provider_reference' => 'ref-1',
    ]);

    $props = $this->actingAs($user)
        ->get("http://{$host}/dashboard", ['HTTP_HOST' => $host])
        ->assertOk()
        ->viewData('page')['props'];

    expect($props['money']['collected'])->toBe(20000);
    expect($props['money']['commission'])->toBe(400);
    expect($props['money']['settles_to_you'])->toBe(19210);
    expect($props['money']['awaiting_payout'])->toBe(19210);
});

test('a tenant with no events gets an empty state rather than a broken page', function () {
    [$tenant, $user, $host] = dashboardActor('dash-empty');

    $props = $this->actingAs($user)
        ->get("http://{$host}/dashboard", ['HTTP_HOST' => $host])
        ->assertOk()
        ->viewData('page')['props'];

    expect($props['liveEvent'])->toBeNull();
    expect($props['nextEvent'])->toBeNull();
    expect($props['events'])->toBe([]);
    expect($props['totals']['events'])->toBe(0);
    expect($props['needsAPerson'])->toBe([]);
    expect($props['money']['collected'])->toBe(0);
    expect($props['arrivals']['series'])->toBe([]);
});

test('arrivals are bucketed finely enough to show the shape of the door', function () {
    [$tenant, $user, $host] = dashboardActor('dash-arrivals');

    $event = Event::factory()->published()->create([
        'tenant_id' => $tenant->id,
        'timezone' => 'Africa/Accra',
        'starts_at' => now()->subHour(), 'ends_at' => now()->addHours(6),
    ]);

    // A ninety-minute door. Half-hour buckets would render this as three bars.
    foreach (range(0, 40) as $i) {
        $r = EventRegistration::factory()->create([
            'tenant_id' => $tenant->id, 'event_id' => $event->id,
            'status' => EventRegistration::STATUS_CHECKED_IN,
        ]);
        $r->forceFill(['checked_in_at' => now()->startOfDay()->addHours(8)->addMinutes($i * 2)])->save();
    }

    $props = $this->actingAs($user)
        ->get("http://{$host}/dashboard", ['HTTP_HOST' => $host])
        ->assertOk()
        ->viewData('page')['props'];

    expect($props['arrivals']['step'])->toBeLessThanOrEqual(15);
    expect(count($props['arrivals']['series']))->toBeGreaterThan(5);
    // Every check-in lands in a bucket -- none silently dropped off the ends.
    expect(collect($props['arrivals']['series'])->sum('count'))->toBe(41);
});

test('pending approvals surface as work needing a person', function () {
    [$tenant, $user, $host] = dashboardActor('dash-approvals');

    // Work waiting on a person is surfaced while the event is running, which is
    // when anyone can act on it.
    $event = Event::factory()->published()->create([
        'tenant_id' => $tenant->id,
        'starts_at' => now()->subHour(), 'ends_at' => now()->addHours(4),
    ]);
    EventRegistration::factory()->create([
        'tenant_id' => $tenant->id, 'event_id' => $event->id,
        'full_name' => 'Ama Mensah',
        'status' => EventRegistration::STATUS_PENDING_APPROVAL,
    ]);

    $props = $this->actingAs($user)
        ->get("http://{$host}/dashboard", ['HTTP_HOST' => $host])
        ->assertOk()
        ->viewData('page')['props'];

    expect($props['needsAPerson'])->toHaveCount(1);
    expect($props['needsAPerson'][0]['title'])->toBe('Ama Mensah');
    expect($props['needsAPerson'][0]['tag'])->toBe('Approval');
});
