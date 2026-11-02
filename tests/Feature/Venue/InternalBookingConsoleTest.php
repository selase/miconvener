<?php

declare(strict_types=1);

use App\Models\Member;
use App\Models\Package;
use App\Models\Shop;
use App\Models\StoreListing;
use App\Models\User;
use App\Models\VenueBooking;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;

/**
 * The organisation's console for staff bookings: who may use it, booking on
 * behalf of staff, approving and declining, and choosing which rooms are
 * staff-only. All of it needs the manage room bookings permission and Enterprise.
 */
beforeEach(function (): void {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
    Artisan::call('db:seed', ['--class' => 'EventPackageSeeder']);
    Mail::fake();

    $this->owner = User::factory()->create();
    $this->tenant = setActiveTenantForTest($this->owner, ['package_id' => Package::query()->where('slug', 'enterprise')->value('id')]);
    $this->tenant->syncFeaturesFromPackage();
    $this->owner->assignRole('Org Superadmin');
    $this->shop = Shop::factory()->create(['tenant_id' => $this->tenant->id, 'is_active' => true]);
    $this->room = StoreListing::factory()->create(['shop_id' => $this->shop->id, 'title' => 'Council Chamber', 'listing_kind' => StoreListing::KIND_VENUE, 'status' => StoreListing::STATUS_PUBLISHED, 'audience' => StoreListing::AUDIENCE_STAFF]);
    $this->staff = Member::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Ama Mensah', 'email' => 'ama@ugmc.example', 'is_staff' => true]);
    $this->nine = CarbonImmutable::now()->addDays(3)->setTime(9, 0);
    $this->route = fn (string $name, array $extra = []): string => route("tenant.venue.internal-bookings.{$name}", ['subdomain' => $this->tenant->slug] + $extra);
    $this->payload = fn (array $overrides = []): array => $overrides + [
        'store_listing_id' => $this->room->id, 'member_id' => $this->staff->id,
        'starts_at' => $this->nine->toIso8601String(), 'ends_at' => $this->nine->addHours(2)->toIso8601String(),
        'purpose' => 'Budget review', 'department' => 'Finance',
    ];
});

test('the organisation owner lists pending requests, upcoming bookings, rooms and staff', function (): void {
    VenueBooking::factory()->internal()->create(['tenant_id' => $this->tenant->id, 'shop_id' => $this->shop->id, 'store_listing_id' => $this->room->id, 'requested_for_member_id' => $this->staff->id, 'planner_name' => 'Ama Mensah']);

    $this->actingAs($this->owner)->get(($this->route)('index'))->assertOk()->assertInertia(fn ($page) => $page
        ->component('Tenant/Venue/InternalBookings/Index')
        ->has('pending', 1)
        ->where('pending.0.room', 'Council Chamber')
        ->has('rooms', 1)
        ->has('staff', 1)
        ->where('staff.0.name', 'Ama Mensah')
        ->where('limits.max_hours', 8));
});

test('a team member books on behalf of an existing staff member', function (): void {
    $this->actingAs($this->owner)->post(($this->route)('store'), ($this->payload)())->assertSessionHasNoErrors()->assertRedirect();

    $booking = VenueBooking::query()->where('kind', 'internal')->firstOrFail();
    expect($booking->status)->toBe(VenueBooking::STATUS_PENDING_APPROVAL)
        ->and($booking->requested_for_member_id)->toBe($this->staff->id)
        ->and($booking->entered_by_user_id)->toEqual($this->owner->id);

    $this->actingAs($this->owner)->post(($this->route)('store'), ($this->payload)(['starts_at' => $this->nine->addDay()->toIso8601String(), 'ends_at' => $this->nine->addDay()->addHour()->toIso8601String(), 'confirm' => true]))->assertSessionHasNoErrors();

    expect(VenueBooking::query()->where('status', VenueBooking::STATUS_CONFIRMED)->count())->toBe(1);
});

test('booking for someone not yet tagged tags or adds them as staff', function (): void {
    $existing = Member::factory()->create(['tenant_id' => $this->tenant->id, 'email' => 'kofi@ugmc.example', 'is_staff' => false]);

    $this->actingAs($this->owner)->post(($this->route)('store'), ($this->payload)(['member_id' => null, 'staff_name' => 'Kofi', 'staff_email' => 'KOFI@ugmc.example']))->assertSessionHasNoErrors();
    expect($existing->fresh()->is_staff)->toBeTrue();

    $this->actingAs($this->owner)->post(($this->route)('store'), ($this->payload)(['member_id' => null, 'staff_name' => 'Efua Owusu', 'staff_email' => 'efua@ugmc.example', 'starts_at' => $this->nine->addDay()->toIso8601String(), 'ends_at' => $this->nine->addDay()->addHour()->toIso8601String()]))->assertSessionHasNoErrors();

    $efua = Member::query()->where('email', 'efua@ugmc.example')->firstOrFail();
    expect($efua->is_staff)->toBeTrue()->and($efua->membershipType->hasDues())->toBeFalse();
});

test('a person is asked for when neither a staff member nor a name and email is given', function (): void {
    $this->actingAs($this->owner)->post(($this->route)('store'), ($this->payload)(['member_id' => null]))->assertSessionHasErrors(['member_id']);
});

test('the console refuses what the booking rules refuse', function (): void {
    $this->actingAs($this->owner)->post(($this->route)('store'), ($this->payload)(['ends_at' => $this->nine->addHours(9)->toIso8601String()]))->assertSessionHasErrors('ends_at');
});

test('a facility manager approves and declines requests', function (): void {
    $make = fn (int $day) => VenueBooking::factory()->internal()->create(['tenant_id' => $this->tenant->id, 'shop_id' => $this->shop->id, 'store_listing_id' => $this->room->id, 'requested_for_member_id' => $this->staff->id, 'planner_email' => 'ama@ugmc.example', 'starts_at' => $this->nine->addDays($day), 'ends_at' => $this->nine->addDays($day)->addHour()]);
    $a = $make(1);
    $b = $make(2);

    $this->actingAs($this->owner)->post(($this->route)('approve', ['booking' => $a->id]))->assertSessionHasNoErrors();
    $this->actingAs($this->owner)->post(($this->route)('decline', ['booking' => $b->id]), ['reason' => 'Closed'])->assertSessionHasNoErrors();
    $this->actingAs($this->owner)->post(($this->route)('decline', ['booking' => $a->id]), [])->assertSessionHasErrors('reason');

    expect($a->fresh()->status)->toBe(VenueBooking::STATUS_CONFIRMED)->and($b->fresh()->status)->toBe(VenueBooking::STATUS_DECLINED);

    $this->actingAs($this->owner)->post(($this->route)('cancel', ['booking' => $a->id]))->assertSessionHasNoErrors();
    expect($a->fresh()->status)->toBe(VenueBooking::STATUS_CANCELLED);
});

test('a booking of another organisation cannot be touched', function (): void {
    $other = VenueBooking::factory()->internal()->create();

    $this->actingAs($this->owner)->post(($this->route)('approve', ['booking' => $other->id]))->assertNotFound();
});

test('without the permission or the plan, every route is forbidden', function (): void {
    $admin = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->tenant->users()->attach($admin->id);
    setPermissionsTeamId($this->tenant->id);
    $admin->assignRole('Org Admin');
    $admin->revokePermissionTo('manage room bookings');
    $booking = VenueBooking::factory()->internal()->create(['tenant_id' => $this->tenant->id, 'shop_id' => $this->shop->id, 'store_listing_id' => $this->room->id]);

    $this->actingAs($admin)->get(($this->route)('index'))->assertForbidden();
    $this->actingAs($admin)->post(($this->route)('store'), ($this->payload)())->assertForbidden();
    $this->actingAs($admin)->post(($this->route)('approve', ['booking' => $booking->id]))->assertForbidden();

    $this->tenant->update(['package_id' => Package::query()->where('slug', 'growth')->value('id')]);
    $this->tenant->syncFeaturesFromPackage();

    $this->actingAs($this->owner)->get(($this->route)('index'))->assertForbidden();
    $this->actingAs($this->owner)->post(($this->route)('store'), ($this->payload)())->assertForbidden();
});

test('the room form saves who a room is for and whether staff requests need approval', function (): void {
    $public = StoreListing::factory()->create(['shop_id' => $this->shop->id, 'title' => 'Open Hall', 'slug' => 'open-hall', 'listing_kind' => StoreListing::KIND_VENUE, 'status' => StoreListing::STATUS_PUBLISHED]);
    $payload = fn (array $extra) => $extra + [
        'title' => 'Open Hall', 'slug' => 'open-hall', 'rental_price' => 100, 'pricing_model' => StoreListing::PRICING_MODEL_PER_DAY,
        'price_visibility' => StoreListing::PRICE_VISIBILITY_PUBLIC, 'status' => StoreListing::STATUS_PUBLISHED,
    ];

    $this->actingAs($this->owner)->put(route('tenant.venue.spaces.update', ['subdomain' => $this->tenant->slug, 'listing' => $public->id]), $payload(['audience' => 'staff', 'requires_approval' => false]))->assertSessionHasNoErrors();

    expect($public->fresh()->audience)->toBe('staff')->and($public->fresh()->requires_approval)->toBeFalse();

    $this->actingAs($this->owner)->put(route('tenant.venue.spaces.update', ['subdomain' => $this->tenant->slug, 'listing' => $public->id]), $payload(['audience' => 'nobody']))->assertSessionHasErrors('audience');
});

test('an organisation that left Enterprise can make rooms public but not staff-only', function (): void {
    $this->tenant->update(['package_id' => Package::query()->where('slug', 'growth')->value('id')]);
    $this->tenant->syncFeaturesFromPackage();
    $payload = fn (string $audience) => [
        'title' => 'Council Chamber', 'slug' => $this->room->slug, 'rental_price' => 100, 'pricing_model' => StoreListing::PRICING_MODEL_PER_DAY,
        'price_visibility' => StoreListing::PRICE_VISIBILITY_PUBLIC, 'status' => StoreListing::STATUS_PUBLISHED, 'audience' => $audience,
    ];
    $url = route('tenant.venue.spaces.update', ['subdomain' => $this->tenant->slug, 'listing' => $this->room->id]);

    $this->actingAs($this->owner)->put($url, $payload('public'))->assertSessionHasNoErrors();
    expect($this->room->fresh()->audience)->toBe('public');

    $this->actingAs($this->owner)->put($url, $payload('staff'))->assertSessionHasErrors('audience');
});

test('the calendar shows staff bookings with their kind, department and purpose', function (): void {
    VenueBooking::factory()->internal()->create(['tenant_id' => $this->tenant->id, 'shop_id' => $this->shop->id, 'store_listing_id' => $this->room->id, 'department' => 'Finance', 'purpose' => 'Budget review', 'starts_at' => now()->startOfMonth()->setTime(9, 0), 'ends_at' => now()->startOfMonth()->setTime(11, 0)]);

    $this->actingAs($this->owner)->get(route('tenant.venue.calendar.index', ['subdomain' => $this->tenant->slug]))->assertOk()->assertInertia(fn ($page) => $page
        ->where('events', fn ($events) => collect($events)->contains(fn (array $e): bool => ($e['kind'] ?? null) === 'internal' && ($e['department'] ?? null) === 'Finance' && ($e['purpose'] ?? null) === 'Budget review')));
});

test('the calendar opens the month asked for on the 31st instead of overflowing into the next', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-31 10:00:00'));

    $this->actingAs($this->owner)->get(route('tenant.venue.calendar.index', ['subdomain' => $this->tenant->slug, 'month' => '2026-11']))
        ->assertOk()->assertInertia(fn ($page) => $page->where('month', '2026-11'));
});

test('the facility team adds and removes staff from the staff list', function (): void {
    $this->actingAs($this->owner)->post(($this->route)('staff.store'), ['name' => 'Efua Owusu', 'email' => 'Efua@ugmc.example'])->assertSessionHasNoErrors();

    $efua = Member::query()->where('tenant_id', $this->tenant->id)->where('email', 'efua@ugmc.example')->firstOrFail();
    expect($efua->is_staff)->toBeTrue()->and($efua->membershipType->hasDues())->toBeFalse();

    $this->actingAs($this->owner)->delete(($this->route)('staff.destroy', ['member' => $efua->id]))->assertSessionHasNoErrors();

    expect($efua->fresh()->is_staff)->toBeFalse()->and(Member::query()->whereKey($efua->id)->exists())->toBeTrue();
});

test('removing someone from staff cancels nothing already confirmed but stops new requests', function (): void {
    $booking = VenueBooking::factory()->internal()->create(['tenant_id' => $this->tenant->id, 'shop_id' => $this->shop->id, 'store_listing_id' => $this->room->id, 'requested_for_member_id' => $this->staff->id, 'status' => VenueBooking::STATUS_CONFIRMED]);

    $this->actingAs($this->owner)->delete(($this->route)('staff.destroy', ['member' => $this->staff->id]))->assertSessionHasNoErrors();

    expect($booking->fresh()->status)->toBe(VenueBooking::STATUS_CONFIRMED);
    $this->actingAs($this->owner)->post(($this->route)('store'), ($this->payload)())->assertSessionHasErrors('member');
});

test('a member of another organisation cannot be removed from staff here', function (): void {
    $other = Member::factory()->create(['is_staff' => true]);

    $this->actingAs($this->owner)->delete(($this->route)('staff.destroy', ['member' => $other->id]))->assertNotFound();
    expect($other->fresh()->is_staff)->toBeTrue();
});

test('an organisation sets its own booking limits, and they are the ones enforced', function (): void {
    $this->actingAs($this->owner)->put(($this->route)('limits.update'), ['max_hours' => 2, 'window_days' => 30, 'open_requests' => 3])->assertSessionHasNoErrors();

    expect($this->tenant->fresh()->booking_limits)->toBe(['max_hours' => 2, 'window_days' => 30, 'open_requests' => 3]);

    $this->actingAs($this->owner)->post(($this->route)('store'), ($this->payload)(['ends_at' => $this->nine->addHours(3)->toIso8601String()]))->assertSessionHasErrors('ends_at');
    $this->actingAs($this->owner)->get(($this->route)('index'))->assertInertia(fn ($page) => $page->where('limits.max_hours', 2)->where('limits.window_days', 30)->where('limits.open_requests', 3));
});

test('booking limits stay within sensible bounds and need the permission', function (): void {
    $this->actingAs($this->owner)->put(($this->route)('limits.update'), ['max_hours' => 0, 'window_days' => 1000, 'open_requests' => 'many'])->assertSessionHasErrors(['max_hours', 'window_days', 'open_requests']);

    $admin = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->tenant->users()->attach($admin->id);
    setPermissionsTeamId($this->tenant->id);
    $admin->assignRole('Org Admin');
    $admin->revokePermissionTo('manage room bookings');

    $this->actingAs($admin)->put(($this->route)('limits.update'), ['max_hours' => 4, 'window_days' => 30, 'open_requests' => 3])->assertForbidden();
});
