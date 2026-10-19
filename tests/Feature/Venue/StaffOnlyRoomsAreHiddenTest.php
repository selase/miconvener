<?php

declare(strict_types=1);

use App\Models\Shop;
use App\Models\StoreListing;
use App\Models\Tenant;
use App\Services\Marketplace\MarketplaceSearchService;

/**
 * A staff-only room must not exist for anyone outside the organisation, on any
 * public path: pages, search, booking and quote endpoints, the AI tools. The
 * rule is a global scope, so a path nobody listed here is hidden as well.
 */
beforeEach(function (): void {
    refreshTenantDatabases();

    $this->tenant = Tenant::factory()->create();
    $this->shop = Shop::factory()->create(['tenant_id' => $this->tenant->id, 'is_active' => true]);

    $make = fn (string $slug, string $audience): StoreListing => StoreListing::factory()->create([
        'shop_id' => $this->shop->id,
        'slug' => $slug,
        'title' => "Room {$slug}",
        'listing_kind' => StoreListing::KIND_VENUE,
        'status' => StoreListing::STATUS_PUBLISHED,
        'audience' => $audience,
        'is_bookable' => true,
    ]);

    $this->public = $make('open-hall', StoreListing::AUDIENCE_PUBLIC);
    $this->staffOnly = $make('staff-boardroom', StoreListing::AUDIENCE_STAFF);
});

/**
 * @return array<string, array{0: string, 1: string, 2: array<string, mixed>}>
 */
function publicRoomPaths(): array
{
    $start = now()->addDays(3)->setTime(9, 0);

    return [
        'listing page' => ['get', '/marketplace/venues/staff-boardroom', []],
        'booked slots' => ['get', '/marketplace/venues/staff-boardroom/booked-slots', []],
        'check availability' => ['post', '/marketplace/venues/staff-boardroom/check-availability', ['starts_at' => $start->toIso8601String(), 'ends_at' => $start->copy()->addHours(2)->toIso8601String()]],
        'book' => ['post', '/marketplace/venues/staff-boardroom/book', ['starts_at' => $start->toIso8601String(), 'ends_at' => $start->copy()->addHours(2)->toIso8601String(), 'planner_name' => 'A', 'planner_email' => 'a@example.com', 'event_type' => 'Meeting', 'guest_count' => 5, 'layout_style' => 'boardroom', 'agreed_to_terms' => '1']],
        'request for quote' => ['post', '/marketplace/listings/staff-boardroom/rfq', ['planner_name' => 'A', 'planner_email' => 'a@example.com', 'event_title' => 'Meeting', 'guest_count' => 5, 'event_date' => now()->addDays(5)->toDateString(), 'requirements_description' => 'A boardroom for ten people.']],
    ];
}

test('a staff-only room is a 404 on every public room endpoint', function (string $method, string $path, array $data): void {
    $this->{$method}($path, $data)->assertNotFound();
})->with(fn () => array_map(fn (array $p): array => $p, publicRoomPaths()));

test('the public room is still reachable, so the paths above fail for the right reason', function (): void {
    $this->get('/marketplace/venues/open-hall')->assertOk();
});

test('the marketplace listing and search never include a staff-only room', function (): void {
    $this->get('/marketplace/venues')->assertOk()->assertSee('Room open-hall')->assertDontSee('Room staff-boardroom');
    $this->get('/marketplace')->assertOk()->assertDontSee('Room staff-boardroom');

    $slugs = app(MarketplaceSearchService::class)->searchVenues([], 50)->pluck('slug')->all();
    expect($slugs)->toContain('open-hall')->not->toContain('staff-boardroom');
});

test('the AI tools answer for a staff-only room exactly as for a room that does not exist', function (string $tool, string $slugKey, array $arguments): void {
    $call = fn (string $slug) => (string) $this->postJson('/mcp/marketplace', [
        'jsonrpc' => '2.0', 'id' => 9, 'method' => 'tools/call',
        'params' => ['name' => $tool, 'arguments' => [...$arguments, $slugKey => $slug]],
    ])->getContent();

    $forStaffOnly = $call('staff-boardroom');

    expect($forStaffOnly)->not->toContain('Room staff-boardroom')
        ->and(str_replace('staff-boardroom', 'no-such-room', $forStaffOnly))->toBe($call('no-such-room'));
})->with([
    'details' => ['get_venue_details', 'slug', []],
    'availability' => ['check_venue_availability', 'slug', []],
    'quote' => ['request_venue_quote', 'venue_slug', ['planner_name' => 'A', 'planner_email' => 'a@example.com', 'event_type' => 'Meeting', 'event_date' => '2030-01-01', 'guest_count' => 5]],
]);

test('searching by the AI tool never lists a staff-only room', function (): void {
    $response = (string) $this->postJson('/mcp/marketplace', [
        'jsonrpc' => '2.0', 'id' => 9, 'method' => 'tools/call',
        'params' => ['name' => 'search_venues', 'arguments' => ['city' => 'Accra']],
    ])->getContent();

    expect($response)->not->toContain('staff-boardroom');
});

test('rooms to embed for search never include a staff-only room', function (): void {
    $embeddable = StoreListing::query()->where('status', StoreListing::STATUS_PUBLISHED)->pluck('slug')->all();

    expect($embeddable)->toContain('open-hall')->not->toContain('staff-boardroom');
});

test('code that opts in sees the staff-only rooms of the owning shop only', function (): void {
    $other = Shop::factory()->create(['tenant_id' => Tenant::factory()->create()->id, 'is_active' => true]);
    StoreListing::factory()->create(['shop_id' => $other->id, 'slug' => 'other-staff-room', 'status' => StoreListing::STATUS_PUBLISHED, 'audience' => StoreListing::AUDIENCE_STAFF]);

    $slugs = StoreListing::query()->visibleToStaffOf($this->tenant)->pluck('slug')->all();

    expect($slugs)->toContain('open-hall')->toContain('staff-boardroom')->not->toContain('other-staff-room');
    expect(StoreListing::query()->withStaffOnly()->where('slug', 'staff-boardroom')->exists())->toBeTrue();
});

test('the public booking pages refuse an internal booking, even with its reference', function (): void {
    $booking = App\Models\VenueBooking::factory()->internal()->create([
        'tenant_id' => $this->tenant->id, 'shop_id' => $this->shop->id, 'store_listing_id' => $this->staffOnly->id,
        'booking_reference' => 'BKG-INTERNAL1',
    ]);

    $this->get('/marketplace/bookings/BKG-INTERNAL1')->assertNotFound();
    $this->post('/marketplace/bookings/BKG-INTERNAL1/checkout')->assertNotFound();
    $this->get('/marketplace/bookings/BKG-INTERNAL1/callback?reference=x')->assertNotFound();
    expect($booking->fresh()->listing->slug)->toBe('staff-boardroom');
});

test('the owning organisation still lists, opens and sees the calendar of its staff-only rooms', function (): void {
    Illuminate\Support\Facades\Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Illuminate\Support\Facades\Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
    $user = App\Models\User::factory()->create();
    $tenant = setActiveTenantForTest($user);
    $user->assignRole('Org Superadmin');
    $shop = Shop::factory()->create(['tenant_id' => $tenant->id, 'is_active' => true]);
    $room = StoreListing::factory()->create(['shop_id' => $shop->id, 'title' => 'Council Chamber', 'listing_kind' => StoreListing::KIND_VENUE, 'status' => StoreListing::STATUS_PUBLISHED, 'audience' => StoreListing::AUDIENCE_STAFF]);

    $this->actingAs($user)->get(route('tenant.venue.spaces.index', ['subdomain' => $tenant->slug]))->assertOk()->assertSee('Council Chamber');
    $this->actingAs($user)->get(route('tenant.venue.spaces.edit', ['subdomain' => $tenant->slug, 'listing' => $room->id]))->assertOk();
    $this->actingAs($user)->get(route('tenant.venue.calendar.index', ['subdomain' => $tenant->slug]))->assertOk()->assertSee('Council Chamber');
});

test('another organisation cannot open a staff-only room, and is told it does not exist', function (): void {
    Illuminate\Support\Facades\Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Illuminate\Support\Facades\Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
    $user = App\Models\User::factory()->create();
    $tenant = setActiveTenantForTest($user);
    $user->assignRole('Org Superadmin');
    Shop::factory()->create(['tenant_id' => $tenant->id, 'is_active' => true]);

    $this->actingAs($user)->get(route('tenant.venue.spaces.edit', ['subdomain' => $tenant->slug, 'listing' => $this->staffOnly->id]))->assertNotFound();
});
