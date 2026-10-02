<?php

declare(strict_types=1);

namespace Tests\Feature\Tenant;

use App\Mail\Marketplace\QuoteSentNotification;
use App\Models\Shop;
use App\Models\StoreListing;
use App\Models\Tenant;
use App\Models\User;
use App\Models\VenueBooking;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;

beforeEach(function (): void {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);

    // Host Tenant A
    $this->tenantA = Tenant::factory()->create(['slug' => 'host-hotel-a', 'isolation_mode' => 'shared']);
    $this->userA = User::factory()->create(['tenant_id' => $this->tenantA->id]);
    setPermissionsTeamId($this->tenantA->id);
    $this->userA->assignRole('Org Admin');
    $this->tenantA->users()->attach($this->userA->id);

    $this->shopA = Shop::create([
        'tenant_id' => $this->tenantA->id,
        'name' => 'Host Hotel A Accra',
        'slug' => 'host-hotel-a-'.uniqid(),
        'email' => 'events@hotel-a.com',
        'phone' => '+233 24 111 2222',
        'address' => 'Airport Residential Area',
        'city' => 'Accra',
        'region' => 'Greater Accra',
        'verification_status' => 'verified',
        'is_active' => true,
    ]);

    $this->listingA = StoreListing::create([
        'shop_id' => $this->shopA->id,
        'listing_kind' => StoreListing::KIND_VENUE,
        'title' => 'Ballroom A',
        'slug' => 'ballroom-a-'.uniqid(),
        'rental_price_pesewas' => 1500000,
        'pricing_model' => StoreListing::PRICING_MODEL_PER_DAY,
        'price_visibility' => StoreListing::PRICE_VISIBILITY_PUBLIC,
        'status' => StoreListing::STATUS_PUBLISHED,
    ]);

    // Host Tenant B (for cross-tenant boundary audit)
    $this->tenantB = Tenant::factory()->create(['slug' => 'host-hotel-b', 'isolation_mode' => 'shared']);
    $this->userB = User::factory()->create(['tenant_id' => $this->tenantB->id]);
    setPermissionsTeamId($this->tenantB->id);
    $this->userB->assignRole('Org Admin');
    $this->tenantB->users()->attach($this->userB->id);

    $this->shopB = Shop::create([
        'tenant_id' => $this->tenantB->id,
        'name' => 'Host Hotel B Cantonments',
        'slug' => 'host-hotel-b-'.uniqid(),
        'email' => 'events@hotel-b.com',
        'phone' => '+233 24 333 4444',
        'address' => 'Cantonments',
        'city' => 'Accra',
        'region' => 'Greater Accra',
        'verification_status' => 'verified',
        'is_active' => true,
    ]);

    $this->listingB = StoreListing::create([
        'shop_id' => $this->shopB->id,
        'listing_kind' => StoreListing::KIND_VENUE,
        'title' => 'Ballroom B',
        'slug' => 'ballroom-b-'.uniqid(),
        'rental_price_pesewas' => 2000000,
        'pricing_model' => StoreListing::PRICING_MODEL_PER_DAY,
        'price_visibility' => StoreListing::PRICE_VISIBILITY_PUBLIC,
        'status' => StoreListing::STATUS_PUBLISHED,
    ]);
});

test('unauthorized tenant user without manage venue permission is forbidden', function (): void {
    $guestUser = User::factory()->create(['tenant_id' => $this->tenantA->id]);
    $this->tenantA->users()->attach($guestUser->id);

    $host = "{$this->tenantA->slug}.".mb_ltrim((string) config('session.domain'), '.');

    $this->actingAs($guestUser)
        ->get("http://{$host}/venue/inquiries", ['HTTP_HOST' => $host])
        ->assertForbidden();
});

test('host tenant admin can access venue inquiries inbox with pipeline metrics', function (): void {
    // Create bookings for shop A
    VenueBooking::create([
        'tenant_id' => $this->tenantA->id,
        'store_listing_id' => $this->listingA->id,
        'shop_id' => $this->shopA->id,
        'booking_reference' => 'INQ-AAA111',
        'planner_name' => 'Akosua Darko',
        'planner_email' => 'akosua@agency.com',
        'event_type' => 'Corporate Gala',
        'guest_count' => 150,
        'layout_style' => 'banquet',
        'starts_at' => Carbon::tomorrow()->setTime(18, 0),
        'ends_at' => Carbon::tomorrow()->setTime(23, 0),
        'rental_amount_pesewas' => 1500000,
        'security_deposit_pesewas' => 200000,
        'total_amount_pesewas' => 1700000,
        'deposit_required_pesewas' => 1700000,
        'status' => VenueBooking::STATUS_PENDING_QUOTE,
        'payment_status' => VenueBooking::PAYMENT_UNPAID,
    ]);

    VenueBooking::create([
        'tenant_id' => $this->tenantA->id,
        'store_listing_id' => $this->listingA->id,
        'shop_id' => $this->shopA->id,
        'booking_reference' => 'VBK-CONFIRMED',
        'planner_name' => 'Kojo Antwi',
        'planner_email' => 'kojo@music.com',
        'event_type' => 'Acoustic Concert',
        'guest_count' => 200,
        'layout_style' => 'theater',
        'starts_at' => Carbon::tomorrow()->addDays(2)->setTime(19, 0),
        'ends_at' => Carbon::tomorrow()->addDays(2)->setTime(23, 0),
        'rental_amount_pesewas' => 2000000,
        'security_deposit_pesewas' => 300000,
        'total_amount_pesewas' => 2300000,
        'deposit_required_pesewas' => 2300000,
        'amount_paid_pesewas' => 2300000,
        'status' => VenueBooking::STATUS_CONFIRMED,
        'payment_status' => VenueBooking::PAYMENT_FULLY_PAID,
    ]);

    $host = "{$this->tenantA->slug}.".mb_ltrim((string) config('session.domain'), '.');

    $response = $this->actingAs($this->userA)
        ->get("http://{$host}/venue/inquiries", ['HTTP_HOST' => $host]);

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Tenant/Venue/Inquiries/Index')
            ->where('stats.total_leads', 2)
            ->where('stats.pending_quotes', 1)
            ->where('stats.confirmed_bookings', 1)
            ->where('stats.total_revenue_pesewas', 2300000)
            ->has('inquiries.data', 2)
        );
});

test('tenant isolation prevents Tenant A from viewing or modifying Tenant B bookings', function (): void {
    $bookingB = VenueBooking::create([
        'tenant_id' => $this->tenantB->id,
        'store_listing_id' => $this->listingB->id,
        'shop_id' => $this->shopB->id,
        'booking_reference' => 'INQ-TENANT-B',
        'planner_name' => 'Esi Hammond',
        'planner_email' => 'esi@hammond.com',
        'event_type' => 'Private Banquet',
        'guest_count' => 80,
        'layout_style' => 'banquet',
        'starts_at' => Carbon::tomorrow()->setTime(10, 0),
        'ends_at' => Carbon::tomorrow()->setTime(18, 0),
        'status' => VenueBooking::STATUS_PENDING_QUOTE,
        'payment_status' => VenueBooking::PAYMENT_UNPAID,
    ]);

    $hostA = "{$this->tenantA->slug}.".mb_ltrim((string) config('session.domain'), '.');

    // Tenant A accessing Tenant B's inquiry via Tenant A's route must 404
    $this->actingAs($this->userA)
        ->get("http://{$hostA}/venue/inquiries/{$bookingB->id}", ['HTTP_HOST' => $hostA])
        ->assertNotFound();

    // Tenant A attempting to accept Tenant B's inquiry must 404
    $this->actingAs($this->userA)
        ->post("http://{$hostA}/venue/inquiries/{$bookingB->id}/hold", [], ['HTTP_HOST' => $hostA])
        ->assertNotFound();
});

test('host can place 48-hour hold on pending quote inquiry', function (): void {
    $booking = VenueBooking::create([
        'tenant_id' => $this->tenantA->id,
        'store_listing_id' => $this->listingA->id,
        'shop_id' => $this->shopA->id,
        'booking_reference' => 'INQ-HOLD-ME',
        'planner_name' => 'Kofi Amoah',
        'planner_email' => 'kofi@amoah.com',
        'event_type' => 'Executive Meeting',
        'guest_count' => 25,
        'layout_style' => 'boardroom',
        'starts_at' => Carbon::tomorrow()->setTime(9, 0),
        'ends_at' => Carbon::tomorrow()->setTime(17, 0),
        'status' => VenueBooking::STATUS_PENDING_QUOTE,
        'payment_status' => VenueBooking::PAYMENT_UNPAID,
    ]);

    $host = "{$this->tenantA->slug}.".mb_ltrim((string) config('session.domain'), '.');

    $response = $this->actingAs($this->userA)
        ->post("http://{$host}/venue/inquiries/{$booking->id}/hold", [], ['HTTP_HOST' => $host]);

    $response->assertRedirect();

    $booking->refresh();
    expect($booking->status)->toBe(VenueBooking::STATUS_PENDING_PAYMENT)
        ->and($booking->host_notes)->toContain('48-hour tentative reservation hold');
});

test('host can issue custom quote which recalculates pesewas and notifies planner', function (): void {
    Mail::fake();

    $booking = VenueBooking::create([
        'tenant_id' => $this->tenantA->id,
        'store_listing_id' => $this->listingA->id,
        'shop_id' => $this->shopA->id,
        'booking_reference' => 'INQ-QUOTE-REQ',
        'planner_name' => 'Ewuresi Taylor',
        'planner_email' => 'ewuresi@taylor.com',
        'event_type' => 'Tech Summit Welcome Reception',
        'guest_count' => 120,
        'layout_style' => 'cocktail',
        'starts_at' => Carbon::tomorrow()->setTime(17, 0),
        'ends_at' => Carbon::tomorrow()->setTime(22, 0),
        'status' => VenueBooking::STATUS_PENDING_QUOTE,
        'payment_status' => VenueBooking::PAYMENT_UNPAID,
    ]);

    $host = "{$this->tenantA->slug}.".mb_ltrim((string) config('session.domain'), '.');

    $response = $this->actingAs($this->userA)
        ->post("http://{$host}/venue/inquiries/{$booking->id}/quote", [
            'rental_amount_ghs' => 12500, // GHS 12,500
            'security_deposit_ghs' => 2500, // GHS 2,500
            'deposit_required_ghs' => 5000, // GHS 5,000 required to lock slot
            'host_notes' => 'Includes premium audio system and dedicated technician.',
        ], ['HTTP_HOST' => $host]);

    $response->assertRedirect();

    $booking->refresh();
    expect($booking->status)->toBe(VenueBooking::STATUS_PENDING_PAYMENT)
        ->and($booking->rental_amount_pesewas)->toBe(1250000)
        ->and($booking->security_deposit_pesewas)->toBe(250000)
        ->and($booking->total_amount_pesewas)->toBe(1500000)
        ->and($booking->deposit_required_pesewas)->toBe(500000)
        ->and($booking->quote_valid_until)->not->toBeNull()
        ->and($booking->host_notes)->toBe('Includes premium audio system and dedicated technician.');

    Mail::assertQueued(QuoteSentNotification::class, function ($mail) use ($booking): bool {
        return $mail->hasTo($booking->planner_email);
    });
});

test('host can reject a venue inquiry with reason', function (): void {
    $booking = VenueBooking::create([
        'tenant_id' => $this->tenantA->id,
        'store_listing_id' => $this->listingA->id,
        'shop_id' => $this->shopA->id,
        'booking_reference' => 'INQ-REJECT-ME',
        'planner_name' => 'Paa Kwesi',
        'planner_email' => 'pk@ghana.com',
        'event_type' => 'Private Party',
        'guest_count' => 300,
        'layout_style' => 'cocktail',
        'starts_at' => Carbon::tomorrow()->setTime(20, 0),
        'ends_at' => Carbon::tomorrow()->addDay()->setTime(2, 0),
        'status' => VenueBooking::STATUS_PENDING_QUOTE,
        'payment_status' => VenueBooking::PAYMENT_UNPAID,
    ]);

    $host = "{$this->tenantA->slug}.".mb_ltrim((string) config('session.domain'), '.');

    $response = $this->actingAs($this->userA)
        ->post("http://{$host}/venue/inquiries/{$booking->id}/reject", [
            'reason' => 'Requested event hours conflict with municipal noise ordinances.',
        ], ['HTTP_HOST' => $host]);

    $response->assertRedirect();

    $booking->refresh();
    expect($booking->status)->toBe(VenueBooking::STATUS_REJECTED)
        ->and($booking->host_notes)->toBe('Requested event hours conflict with municipal noise ordinances.');
});

test('cannot modify or decline an already confirmed or deposit paid reservation', function (): void {
    $confirmedBooking = VenueBooking::create([
        'tenant_id' => $this->tenantA->id,
        'store_listing_id' => $this->listingA->id,
        'shop_id' => $this->shopA->id,
        'booking_reference' => 'VBK-PAID-ALREADY',
        'planner_name' => 'Adwoa Safo',
        'planner_email' => 'adwoa@safo.com',
        'event_type' => 'Confirmed Summit',
        'guest_count' => 100,
        'layout_style' => 'banquet',
        'starts_at' => Carbon::tomorrow()->setTime(10, 0),
        'ends_at' => Carbon::tomorrow()->setTime(18, 0),
        'status' => VenueBooking::STATUS_CONFIRMED,
        'payment_status' => VenueBooking::PAYMENT_DEPOSIT_PAID,
    ]);

    $host = "{$this->tenantA->slug}.".mb_ltrim((string) config('session.domain'), '.');

    // Attempting to hold
    $this->actingAs($this->userA)
        ->post("http://{$host}/venue/inquiries/{$confirmedBooking->id}/hold", [], ['HTTP_HOST' => $host])
        ->assertRedirect()
        ->assertSessionHas('error', 'Cannot modify an already confirmed or paid reservation.');

    // Attempting to quote
    $this->actingAs($this->userA)
        ->post("http://{$host}/venue/inquiries/{$confirmedBooking->id}/quote", [
            'rental_amount_ghs' => 5000,
            'deposit_required_ghs' => 2000,
        ], ['HTTP_HOST' => $host])
        ->assertRedirect()
        ->assertSessionHas('error', 'Cannot modify an already confirmed or paid reservation.');

    // Attempting to reject
    $this->actingAs($this->userA)
        ->post("http://{$host}/venue/inquiries/{$confirmedBooking->id}/reject", [
            'reason' => 'Too late to reject',
        ], ['HTTP_HOST' => $host])
        ->assertRedirect()
        ->assertSessionHas('error', 'Cannot decline an already confirmed or paid reservation.');

    $confirmedBooking->refresh();
    expect($confirmedBooking->status)->toBe(VenueBooking::STATUS_CONFIRMED);
});
