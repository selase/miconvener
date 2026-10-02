<?php

declare(strict_types=1);

namespace Tests\Feature\Tenant;

use App\Models\Shop;
use App\Models\StoreListing;
use App\Models\Tenant;
use App\Models\User;
use App\Models\VenueBooking;
use App\Services\Marketplace\VenueAvailabilityService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);

    // Host Tenant A (Labadi Beach Hotel)
    $this->tenantA = Tenant::factory()->create(['slug' => 'labadi-hotel', 'isolation_mode' => 'shared']);
    $this->userA = User::factory()->create(['tenant_id' => $this->tenantA->id]);
    setPermissionsTeamId($this->tenantA->id);
    $this->userA->assignRole('Org Admin');
    $this->tenantA->users()->attach($this->userA->id);

    $this->shopA = Shop::create([
        'tenant_id' => $this->tenantA->id,
        'name' => 'Labadi Beach Hotel',
        'slug' => 'labadi-beach-hotel-'.uniqid(),
        'email' => 'events@labadibeach.com',
        'phone' => '+233 30 277 2501',
        'address' => 'No 1 La Bypass',
        'city' => 'Accra',
        'region' => 'Greater Accra',
        'verification_status' => 'verified',
        'is_active' => true,
    ]);

    $this->listingA = StoreListing::create([
        'shop_id' => $this->shopA->id,
        'listing_kind' => StoreListing::KIND_VENUE,
        'title' => 'Omanye Plenary Hall',
        'slug' => 'omanye-hall-'.uniqid(),
        'rental_price_pesewas' => 2500000,
        'pricing_model' => StoreListing::PRICING_MODEL_PER_DAY,
        'price_visibility' => StoreListing::PRICE_VISIBILITY_PUBLIC,
        'status' => StoreListing::STATUS_PUBLISHED,
        'capacity_breakdown' => [
            'banquet' => 500,
            'theater' => 800,
            'cocktail' => 1000,
        ],
    ]);

    // Host Tenant B (Kempinski Gold Coast)
    $this->tenantB = Tenant::factory()->create(['slug' => 'kempinski-hotel', 'isolation_mode' => 'shared']);
    $this->userB = User::factory()->create(['tenant_id' => $this->tenantB->id]);
    setPermissionsTeamId($this->tenantB->id);
    $this->userB->assignRole('Org Admin');
    $this->tenantB->users()->attach($this->userB->id);

    $this->shopB = Shop::create([
        'tenant_id' => $this->tenantB->id,
        'name' => 'Kempinski Hotel Gold Coast City',
        'slug' => 'kempinski-accra-'.uniqid(),
        'email' => 'events@kempinski.com',
        'phone' => '+233 24 243 6000',
        'address' => 'Ministries, Gamel Abdul Nasser Ave',
        'city' => 'Accra',
        'region' => 'Greater Accra',
        'verification_status' => 'verified',
        'is_active' => true,
    ]);

    $this->listingB = StoreListing::create([
        'shop_id' => $this->shopB->id,
        'listing_kind' => StoreListing::KIND_VENUE,
        'title' => 'Grand Ballroom B',
        'slug' => 'grand-ballroom-b-'.uniqid(),
        'rental_price_pesewas' => 3500000,
        'pricing_model' => StoreListing::PRICING_MODEL_PER_DAY,
        'price_visibility' => StoreListing::PRICE_VISIBILITY_PUBLIC,
        'status' => StoreListing::STATUS_PUBLISHED,
    ]);

    $this->hostA = "{$this->tenantA->slug}.".mb_ltrim((string) config('session.domain'), '.');
    $this->hostB = "{$this->tenantB->slug}.".mb_ltrim((string) config('session.domain'), '.');
});

test('host can view the venue master calendar with bookings and blackouts', function (): void {
    $now = Carbon::parse('2026-11-15 10:00:00');
    Carbon::setTestNow($now);

    // Staging a confirmed booking in November 2026
    VenueBooking::create([
        'booking_reference' => 'VBK-CONFIRMED',
        'tenant_id' => $this->tenantA->id,
        'shop_id' => $this->shopA->id,
        'store_listing_id' => $this->listingA->id,
        'planner_name' => 'Kwame Mensah',
        'planner_email' => 'kwame@example.com',
        'event_type' => 'Annual Corporate Gala',
        'guest_count' => 300,
        'layout_style' => 'banquet',
        'starts_at' => Carbon::parse('2026-11-20 09:00:00'),
        'ends_at' => Carbon::parse('2026-11-20 18:00:00'),
        'time_slot_type' => 'full_day',
        'pricing_model' => 'per_day',
        'rate_pesewas' => 2500000,
        'duration_units' => 1.0,
        'rental_amount_pesewas' => 2500000,
        'security_deposit_pesewas' => 0,
        'total_amount_pesewas' => 2500000,
        'deposit_required_pesewas' => 625000,
        'amount_paid_pesewas' => 2500000,
        'gateway_fee_pesewas' => 0,
        'status' => VenueBooking::STATUS_CONFIRMED,
        'payment_status' => VenueBooking::PAYMENT_FULLY_PAID,
    ]);

    // Staging a blackout block in November 2026
    VenueBooking::create([
        'booking_reference' => 'BLK-MAINT01',
        'tenant_id' => $this->tenantA->id,
        'shop_id' => $this->shopA->id,
        'store_listing_id' => $this->listingA->id,
        'planner_name' => 'Venue Maintenance / Blackout',
        'planner_email' => $this->shopA->email,
        'event_type' => 'Maintenance / Blackout',
        'guest_count' => 0,
        'layout_style' => 'banquet',
        'starts_at' => Carbon::parse('2026-11-25 08:00:00'),
        'ends_at' => Carbon::parse('2026-11-26 18:00:00'),
        'time_slot_type' => 'full_day',
        'pricing_model' => 'per_day',
        'rate_pesewas' => 0,
        'duration_units' => 2.0,
        'rental_amount_pesewas' => 0,
        'security_deposit_pesewas' => 0,
        'total_amount_pesewas' => 0,
        'deposit_required_pesewas' => 0,
        'amount_paid_pesewas' => 0,
        'gateway_fee_pesewas' => 0,
        'status' => VenueBooking::STATUS_BLOCKED,
        'payment_status' => VenueBooking::PAYMENT_UNPAID,
        'host_notes' => 'Annual Chandelier Cleaning',
    ]);

    $response = $this->actingAs($this->userA)
        ->get("http://{$this->hostA}/venue/calendar?month=2026-11", ['HTTP_HOST' => $this->hostA]);

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Tenant/Venue/Calendar/Index')
        ->has('spaces', 1)
        ->has('events', 2)
        ->where('events.0.booking_reference', 'VBK-CONFIRMED')
        ->where('events.0.status', VenueBooking::STATUS_CONFIRMED)
        ->where('events.1.booking_reference', 'BLK-MAINT01')
        ->where('events.1.status', VenueBooking::STATUS_BLOCKED)
        ->where('events.1.is_blocked', true)
        ->where('events.1.title', 'Annual Chandelier Cleaning')
    );

    Carbon::setTestNow();
});

test('unauthorized user without manage venue permission cannot access venue calendar', function (): void {
    $regularUser = User::factory()->create(['tenant_id' => $this->tenantA->id]);
    $this->tenantA->users()->attach($regularUser->id);

    $this->actingAs($regularUser)
        ->get("http://{$this->hostA}/venue/calendar", ['HTTP_HOST' => $this->hostA])
        ->assertForbidden();
});

test('host can create a manual date blackout block on a space', function (): void {
    $startsAt = Carbon::parse('2026-12-05 08:00:00');
    $endsAt = Carbon::parse('2026-12-07 18:00:00');

    $response = $this->actingAs($this->userA)
        ->post("http://{$this->hostA}/venue/calendar/blocks", [
            'store_listing_id' => $this->listingA->id,
            'starts_at' => $startsAt->toIso8601String(),
            'ends_at' => $endsAt->toIso8601String(),
            'reason' => 'Annual Electrical System Upgrade',
        ], ['HTTP_HOST' => $this->hostA]);

    $response->assertRedirect();
    $response->assertSessionHas('success');

    $this->assertDatabaseHas('venue_bookings', [
        'tenant_id' => $this->tenantA->id,
        'shop_id' => $this->shopA->id,
        'store_listing_id' => $this->listingA->id,
        'status' => VenueBooking::STATUS_BLOCKED,
        'host_notes' => 'Annual Electrical System Upgrade',
        'rental_amount_pesewas' => 0,
        'total_amount_pesewas' => 0,
    ]);
});

test('host cannot create a blackout block that overlaps an already confirmed booking', function (): void {
    $startsAt = Carbon::parse('2026-12-15 09:00:00');
    $endsAt = Carbon::parse('2026-12-15 18:00:00');

    // Existing confirmed booking
    VenueBooking::create([
        'booking_reference' => 'VBK-ALREADY-CONFIRMED',
        'tenant_id' => $this->tenantA->id,
        'shop_id' => $this->shopA->id,
        'store_listing_id' => $this->listingA->id,
        'planner_name' => 'Existing Planner',
        'planner_email' => 'planner@example.com',
        'event_type' => 'Confirmed Summit',
        'guest_count' => 150,
        'layout_style' => 'banquet',
        'starts_at' => $startsAt,
        'ends_at' => $endsAt,
        'time_slot_type' => 'full_day',
        'pricing_model' => 'per_day',
        'rate_pesewas' => 2500000,
        'duration_units' => 1.0,
        'rental_amount_pesewas' => 2500000,
        'security_deposit_pesewas' => 0,
        'total_amount_pesewas' => 2500000,
        'deposit_required_pesewas' => 625000,
        'amount_paid_pesewas' => 2500000,
        'gateway_fee_pesewas' => 0,
        'status' => VenueBooking::STATUS_CONFIRMED,
        'payment_status' => VenueBooking::PAYMENT_FULLY_PAID,
    ]);

    $response = $this->actingAs($this->userA)
        ->post("http://{$this->hostA}/venue/calendar/blocks", [
            'store_listing_id' => $this->listingA->id,
            'starts_at' => $startsAt->copy()->subHours(2)->toIso8601String(),
            'ends_at' => $endsAt->copy()->addHours(2)->toIso8601String(),
            'reason' => 'Emergency Renovation',
        ], ['HTTP_HOST' => $this->hostA]);

    $response->assertSessionHasErrors(['starts_at']);

    $this->assertDatabaseMissing('venue_bookings', [
        'status' => VenueBooking::STATUS_BLOCKED,
        'host_notes' => 'Emergency Renovation',
    ]);
});

test('manual blackout block locks availability across availability service and public API', function (): void {
    $startsAt = Carbon::parse('2026-12-20 09:00:00');
    $endsAt = Carbon::parse('2026-12-20 18:00:00');

    // Create blackout block
    VenueBooking::create([
        'booking_reference' => 'BLK-CHRISTMAS-PREP',
        'tenant_id' => $this->tenantA->id,
        'shop_id' => $this->shopA->id,
        'store_listing_id' => $this->listingA->id,
        'planner_name' => 'Venue Maintenance / Blackout',
        'planner_email' => $this->shopA->email,
        'event_type' => 'Maintenance / Blackout',
        'guest_count' => 0,
        'layout_style' => 'banquet',
        'starts_at' => $startsAt,
        'ends_at' => $endsAt,
        'time_slot_type' => 'full_day',
        'pricing_model' => 'per_day',
        'rate_pesewas' => 0,
        'duration_units' => 1.0,
        'rental_amount_pesewas' => 0,
        'security_deposit_pesewas' => 0,
        'total_amount_pesewas' => 0,
        'deposit_required_pesewas' => 0,
        'amount_paid_pesewas' => 0,
        'gateway_fee_pesewas' => 0,
        'status' => VenueBooking::STATUS_BLOCKED,
        'payment_status' => VenueBooking::PAYMENT_UNPAID,
        'host_notes' => 'Holiday Stage Setup',
    ]);

    /** @var VenueAvailabilityService $service */
    $service = app(VenueAvailabilityService::class);

    // 1. Direct Service Availability Check
    $isAvailable = $service->isSlotAvailable($this->listingA, $startsAt, $endsAt);
    expect($isAvailable)->toBeFalse();

    // 2. Public API Availability Endpoint
    $response = $this->postJson(route('marketplace.venues.check-availability', ['slug' => $this->listingA->slug]), [
        'starts_at' => $startsAt->toIso8601String(),
        'ends_at' => $endsAt->toIso8601String(),
        'guest_count' => 200,
        'layout_style' => 'banquet',
    ]);

    $response->assertOk();
    $response->assertJson([
        'available' => false,
    ]);
});

test('host can remove a manual blackout block and restore availability', function (): void {
    $startsAt = Carbon::parse('2026-12-28 09:00:00');
    $endsAt = Carbon::parse('2026-12-28 18:00:00');

    $block = VenueBooking::create([
        'booking_reference' => 'BLK-TEMP-CLOSURE',
        'tenant_id' => $this->tenantA->id,
        'shop_id' => $this->shopA->id,
        'store_listing_id' => $this->listingA->id,
        'planner_name' => 'Venue Maintenance / Blackout',
        'planner_email' => $this->shopA->email,
        'event_type' => 'Maintenance / Blackout',
        'guest_count' => 0,
        'layout_style' => 'banquet',
        'starts_at' => $startsAt,
        'ends_at' => $endsAt,
        'time_slot_type' => 'full_day',
        'pricing_model' => 'per_day',
        'rate_pesewas' => 0,
        'duration_units' => 1.0,
        'rental_amount_pesewas' => 0,
        'security_deposit_pesewas' => 0,
        'total_amount_pesewas' => 0,
        'deposit_required_pesewas' => 0,
        'amount_paid_pesewas' => 0,
        'gateway_fee_pesewas' => 0,
        'status' => VenueBooking::STATUS_BLOCKED,
        'payment_status' => VenueBooking::PAYMENT_UNPAID,
        'host_notes' => 'Deep Carpet Cleaning',
    ]);

    $response = $this->actingAs($this->userA)
        ->delete("http://{$this->hostA}/venue/calendar/blocks/{$block->id}", [], ['HTTP_HOST' => $this->hostA]);

    $response->assertRedirect();
    $response->assertSessionHas('success');

    $this->assertDatabaseMissing('venue_bookings', [
        'id' => $block->id,
    ]);

    // Service availability is restored
    /** @var VenueAvailabilityService $service */
    $service = app(VenueAvailabilityService::class);
    expect($service->isSlotAvailable($this->listingA, $startsAt, $endsAt))->toBeTrue();
});

test('tenant isolation holds: host cannot delete or view another property blackout blocks', function (): void {
    $startsAt = Carbon::parse('2026-12-30 09:00:00');
    $endsAt = Carbon::parse('2026-12-30 18:00:00');

    // Tenant B's blackout block
    $blockB = VenueBooking::create([
        'booking_reference' => 'BLK-TENANT-B',
        'tenant_id' => $this->tenantB->id,
        'shop_id' => $this->shopB->id,
        'store_listing_id' => $this->listingB->id,
        'planner_name' => 'Venue Staff',
        'planner_email' => $this->shopB->email,
        'event_type' => 'Maintenance / Blackout',
        'guest_count' => 0,
        'layout_style' => 'banquet',
        'starts_at' => $startsAt,
        'ends_at' => $endsAt,
        'time_slot_type' => 'full_day',
        'pricing_model' => 'per_day',
        'rate_pesewas' => 0,
        'duration_units' => 1.0,
        'rental_amount_pesewas' => 0,
        'security_deposit_pesewas' => 0,
        'total_amount_pesewas' => 0,
        'deposit_required_pesewas' => 0,
        'amount_paid_pesewas' => 0,
        'gateway_fee_pesewas' => 0,
        'status' => VenueBooking::STATUS_BLOCKED,
        'payment_status' => VenueBooking::PAYMENT_UNPAID,
        'host_notes' => 'Tenant B Private Event',
    ]);

    // Host A attempts to delete Tenant B's block through Tenant A's endpoint
    $this->actingAs($this->userA)
        ->delete("http://{$this->hostA}/venue/calendar/blocks/{$blockB->id}", [], ['HTTP_HOST' => $this->hostA])
        ->assertNotFound();

    // Verify Tenant B's block is preserved
    $this->assertDatabaseHas('venue_bookings', [
        'id' => $blockB->id,
    ]);
});

test('destroyBlock rejects non-uuid parameter with 404 without SQL exception', function (): void {
    $this->actingAs($this->userA)
        ->delete("http://{$this->hostA}/venue/calendar/blocks/invalid-slug-or-string", [], ['HTTP_HOST' => $this->hostA])
        ->assertNotFound();
});

test('host cannot create a blackout block that overlaps an active unexpired hold', function (): void {
    $startsAt = Carbon::parse('2026-12-18 09:00:00');
    $endsAt = Carbon::parse('2026-12-18 18:00:00');

    // Existing active hold
    VenueBooking::create([
        'booking_reference' => 'INQ-ACTIVE-HOLD',
        'tenant_id' => $this->tenantA->id,
        'shop_id' => $this->shopA->id,
        'store_listing_id' => $this->listingA->id,
        'planner_name' => 'Prospective Planner',
        'planner_email' => 'prospect@example.com',
        'event_type' => 'Pending Hold Gala',
        'guest_count' => 100,
        'layout_style' => 'banquet',
        'starts_at' => $startsAt,
        'ends_at' => $endsAt,
        'time_slot_type' => 'full_day',
        'pricing_model' => 'per_day',
        'rate_pesewas' => 2500000,
        'duration_units' => 1.0,
        'rental_amount_pesewas' => 2500000,
        'security_deposit_pesewas' => 0,
        'total_amount_pesewas' => 2500000,
        'deposit_required_pesewas' => 625000,
        'amount_paid_pesewas' => 0,
        'gateway_fee_pesewas' => 0,
        'status' => VenueBooking::STATUS_PENDING_PAYMENT,
        'payment_status' => VenueBooking::PAYMENT_UNPAID,
        'quote_valid_until' => now()->addHours(24),
    ]);

    $response = $this->actingAs($this->userA)
        ->post("http://{$this->hostA}/venue/calendar/blocks", [
            'store_listing_id' => $this->listingA->id,
            'starts_at' => $startsAt->toIso8601String(),
            'ends_at' => $endsAt->toIso8601String(),
            'reason' => 'Emergency Renovation',
        ], ['HTTP_HOST' => $this->hostA]);

    $response->assertSessionHasErrors(['starts_at']);
});

test('host without a shop profile sees empty calendar state gracefully', function (): void {
    $tenantWithoutShop = Tenant::factory()->create(['slug' => 'no-shop-hotel', 'isolation_mode' => 'shared']);
    $user = User::factory()->create(['tenant_id' => $tenantWithoutShop->id]);
    setPermissionsTeamId($tenantWithoutShop->id);
    $user->assignRole('Org Admin');
    $tenantWithoutShop->users()->attach($user->id);
    $host = "{$tenantWithoutShop->slug}.".mb_ltrim((string) config('session.domain'), '.');

    $this->actingAs($user)
        ->get("http://{$host}/venue/calendar", ['HTTP_HOST' => $host])
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Tenant/Venue/Calendar/Index')
            ->where('shop', null)
            ->has('spaces', 0)
            ->has('events', 0)
        );
});
