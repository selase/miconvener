<?php

declare(strict_types=1);

use App\Mail\Marketplace\BookingConfirmationGuest;
use App\Mail\Marketplace\NewVenueBookingNotification;
use App\Models\Shop;
use App\Models\StoreListing;
use App\Models\Tenant;
use App\Models\VenueBooking;
use App\Services\Marketplace\VenueAvailabilityService;
use App\Services\Marketplace\VenueBookingService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Mail::fake();

    $this->hostTenant = Tenant::factory()->create([
        'name' => 'Kempinski Hotel Gold Coast',
    ]);

    $this->shop = Shop::create([
        'tenant_id' => $this->hostTenant->id,
        'name' => 'Kempinski Hotel Accra',
        'slug' => 'kempinski-accra-'.uniqid(),
        'email' => 'events@kempinski-accra.com',
        'phone' => '+233 24 111 2222',
        'address' => 'Ministries, Gamel Abdul Nasser Ave',
        'city' => 'Accra',
        'region' => 'Greater Accra',
        'verification_status' => 'verified',
        'is_active' => true,
    ]);

    // Hourly listing
    $this->hourlyListing = StoreListing::create([
        'shop_id' => $this->shop->id,
        'listing_kind' => StoreListing::KIND_VENUE,
        'title' => 'Executive Boardroom',
        'slug' => 'executive-boardroom-'.uniqid(),
        'rental_price_pesewas' => 50000, // GHS 500 / hr
        'pricing_model' => StoreListing::PRICING_MODEL_PER_HOUR,
        'price_visibility' => StoreListing::PRICE_VISIBILITY_PUBLIC,
        'security_deposit_pesewas' => 100000, // GHS 1,000
        'capacity_breakdown' => ['boardroom' => 25, 'theater' => 40],
        'status' => StoreListing::STATUS_PUBLISHED,
        'rules_and_policies' => ['outside_catering' => false, 'curfew' => '23:00'],
    ]);

    // Daily listing
    $this->dailyListing = StoreListing::create([
        'shop_id' => $this->shop->id,
        'listing_kind' => StoreListing::KIND_VENUE,
        'title' => 'Grand Ballroom Plenary',
        'slug' => 'grand-ballroom-plenary-'.uniqid(),
        'rental_price_pesewas' => 2500000, // GHS 25,000 / day
        'pricing_model' => StoreListing::PRICING_MODEL_PER_DAY,
        'price_visibility' => StoreListing::PRICE_VISIBILITY_PUBLIC,
        'security_deposit_pesewas' => 500000, // GHS 5,000
        'capacity_breakdown' => ['banquet' => 500, 'theater' => 800],
        'status' => StoreListing::STATUS_PUBLISHED,
        'rules_and_policies' => ['outside_catering' => true, 'noise_limit_db' => 85],
    ]);

    // Price on request listing
    $this->customListing = StoreListing::create([
        'shop_id' => $this->shop->id,
        'listing_kind' => StoreListing::KIND_VENUE,
        'title' => 'Presidential Helipad Grounds',
        'slug' => 'presidential-helipad-'.uniqid(),
        'rental_price_pesewas' => 0,
        'pricing_model' => StoreListing::PRICING_MODEL_PER_DAY,
        'price_visibility' => StoreListing::PRICE_VISIBILITY_ON_REQUEST,
        'capacity_breakdown' => ['cocktail' => 2000],
        'status' => StoreListing::STATUS_PUBLISHED,
    ]);
});

test('availability service correctly calculates duration and pricing for hourly and daily venues', function (): void {
    $service = app(VenueAvailabilityService::class);

    // 1. Hourly calculation (09:00 to 17:00 = 8 hours @ GHS 500 = GHS 4,000 rental + GHS 1,000 deposit)
    $startsAt = Carbon::tomorrow()->setTime(9, 0);
    $endsAt = Carbon::tomorrow()->setTime(17, 0);

    $hourlyPricing = $service->calculatePricing($this->hourlyListing, $startsAt, $endsAt, 20, 'boardroom');

    expect($hourlyPricing['time_slot_type'])->toBe(VenueBooking::SLOT_HOURLY)
        ->and($hourlyPricing['duration_units'])->toBe(8.0)
        ->and($hourlyPricing['rental_amount_pesewas'])->toBe(400000)
        ->and($hourlyPricing['security_deposit_pesewas'])->toBe(100000)
        ->and($hourlyPricing['total_amount_pesewas'])->toBe(500000)
        ->and($hourlyPricing['capacity_check']['valid'])->toBeTrue();

    // 2. Daily calculation (2 full days @ GHS 25,000 = GHS 50,000 rental + GHS 5,000 deposit)
    $day1Start = Carbon::tomorrow()->setTime(8, 0);
    $day2End = Carbon::tomorrow()->addDay()->setTime(20, 0);

    $dailyPricing = $service->calculatePricing($this->dailyListing, $day1Start, $day2End, 400, 'banquet');

    expect($dailyPricing['time_slot_type'])->toBe(VenueBooking::SLOT_MULTI_DAY)
        ->and($dailyPricing['duration_units'])->toBe(2.0)
        ->and($dailyPricing['rental_amount_pesewas'])->toBe(5000000)
        ->and($dailyPricing['security_deposit_pesewas'])->toBe(500000)
        ->and($dailyPricing['total_amount_pesewas'])->toBe(5500000);
});

test('availability service flags capacity overflow when requested guests exceeds layout max', function (): void {
    $service = app(VenueAvailabilityService::class);
    $startsAt = Carbon::tomorrow()->setTime(9, 0);
    $endsAt = Carbon::tomorrow()->setTime(17, 0);

    // Max boardroom capacity is 25, request 35
    $pricing = $service->calculatePricing($this->hourlyListing, $startsAt, $endsAt, 35, 'boardroom');

    expect($pricing['capacity_check']['valid'])->toBeFalse()
        ->and($pricing['capacity_check']['max_capacity'])->toBe(25);
});

test('calendar lockout prevents double-booking overlapping time slots', function (): void {
    $service = app(VenueAvailabilityService::class);
    $bookingService = app(VenueBookingService::class);

    $startsAt = Carbon::tomorrow()->setTime(9, 0);
    $endsAt = Carbon::tomorrow()->setTime(17, 0);

    // First booking is created and confirmed
    $booking1 = $bookingService->createBooking($this->hourlyListing, [
        'planner_name' => 'Kojo Event Group',
        'planner_email' => 'kojo@events.com',
        'event_type' => 'Quarterly Board Meeting',
        'guest_count' => 15,
        'layout_style' => 'boardroom',
        'starts_at' => $startsAt->toDateTimeString(),
        'ends_at' => $endsAt->toDateTimeString(),
        'agreed_to_terms' => true,
    ]);

    $booking1->update(['status' => VenueBooking::STATUS_CONFIRMED]);

    // Overlapping slot (12:00 to 14:00) must be unavailable
    $overlapStart = Carbon::tomorrow()->setTime(12, 0);
    $overlapEnd = Carbon::tomorrow()->setTime(14, 0);
    expect($service->isSlotAvailable($this->hourlyListing, $overlapStart, $overlapEnd))->toBeFalse();

    // Adjacent non-overlapping slot (18:00 to 21:00) must be available
    $adjacentStart = Carbon::tomorrow()->setTime(18, 0);
    $adjacentEnd = Carbon::tomorrow()->setTime(21, 0);
    expect($service->isSlotAvailable($this->hourlyListing, $adjacentStart, $adjacentEnd))->toBeTrue();
});

test('check availability endpoint returns real-time slot availability and pricing', function (): void {
    $start = Carbon::tomorrow()->setTime(9, 0)->toIso8601String();
    $end = Carbon::tomorrow()->setTime(17, 0)->toIso8601String();

    $response = $this->postJson(route('marketplace.venues.check-availability', $this->hourlyListing->slug), [
        'starts_at' => $start,
        'ends_at' => $end,
        'guest_count' => 18,
        'layout_style' => 'boardroom',
    ]);

    $response->assertOk()
        ->assertJson([
            'available' => true,
            'pricing' => [
                'time_slot_type' => 'hourly',
                'duration_units' => 8,
                'rental_amount_pesewas' => 400000,
            ],
        ]);

    // The buyer sees the service fee before paying (a guest pays 3%).
    $deposit = $response->json('pricing.deposit_required_pesewas');
    expect($response->json('pricing.service_fee_percent'))->toEqual(3)
        ->and($response->json('pricing.service_fee_pesewas'))->toBe((int) round($deposit * 3 / 100))
        ->and($response->json('pricing.due_now_pesewas'))->toBe($deposit + (int) round($deposit * 3 / 100));
});

test('booking flow creates pending booking and initiates Paystack deposit checkout', function (): void {
    Http::fake([
        'https://api.paystack.co/customer*' => Http::response(['data' => ['customer_code' => 'CUS_mock123', 'email' => 'akua@mensah.com']], 200),
        'https://api.paystack.co/transaction/initialize' => Http::response([
            'data' => [
                'authorization_url' => 'https://checkout.paystack.com/access_code_123',
                'reference' => 'mock_paystack_ref_123',
            ],
        ], 200),
    ]);

    $start = Carbon::tomorrow()->setTime(9, 0)->format('Y-m-d H:i');
    $end = Carbon::tomorrow()->setTime(17, 0)->format('Y-m-d H:i');

    $response = $this->withHeaders(['X-Inertia' => 'true'])->post(route('marketplace.venues.book', $this->hourlyListing->slug), [
        'planner_name' => 'Akua Mensah',
        'planner_email' => 'akua@mensah.com',
        'planner_phone' => '+233 24 555 6666',
        'planner_company' => 'Apex Healthcare Partners',
        'event_type' => 'Healthcare Summit Board Dinner',
        'guest_count' => 20,
        'layout_style' => 'boardroom',
        'starts_at' => $start,
        'ends_at' => $end,
        'agreed_to_terms' => true,
    ]);

    // Inertia::location redirects to Paystack checkout URL
    $response->assertStatus(409); // Inertia location response code
    expect($response->headers->get('X-Inertia-Location'))->toBe('https://checkout.paystack.com/access_code_123');

    // Booking record must exist in landlord DB
    $booking = VenueBooking::where('planner_email', 'akua@mensah.com')->first();
    expect($booking)->not->toBeNull()
        ->and($booking->status)->toBe(VenueBooking::STATUS_PENDING_PAYMENT)
        ->and($booking->contract_agreed_at)->not->toBeNull()
        ->and($booking->contract_terms_snapshot)->toBeArray();

    // Immediate notification queued to host
    Mail::assertQueued(NewVenueBookingNotification::class, function ($mail): bool {
        return $mail->hasTo($this->shop->email);
    });
});

test('browser callback idempotently confirms venue booking payment and receipt', function (): void {
    $bookingService = app(VenueBookingService::class);
    $startsAt = Carbon::tomorrow()->setTime(9, 0);
    $endsAt = Carbon::tomorrow()->setTime(17, 0);

    $booking = $bookingService->createBooking($this->hourlyListing, [
        'planner_name' => 'Ama Serwaa',
        'planner_email' => 'ama@serwaa.com',
        'event_type' => 'Fintech Strategy Session',
        'guest_count' => 12,
        'layout_style' => 'boardroom',
        'starts_at' => $startsAt->toDateTimeString(),
        'ends_at' => $endsAt->toDateTimeString(),
        'agreed_to_terms' => true,
    ]);

    Http::fake([
        'https://api.paystack.co/transaction/verify/pstk_ref_999' => Http::response([
            'data' => [
                'id' => 999123,
                'status' => 'success',
                'amount' => $booking->deposit_required_pesewas,
                'reference' => 'pstk_ref_999',
                'metadata' => [
                    'booking_reference' => $booking->booking_reference,
                    'venue_booking_id' => $booking->id,
                ],
            ],
        ], 200),
    ]);

    $response = $this->get(route('marketplace.bookings.callback', [
        'reference' => $booking->booking_reference,
        'trxref' => 'pstk_ref_999',
    ]));

    $response->assertRedirect(route('marketplace.bookings.show', $booking->booking_reference));

    $booking->refresh();
    expect($booking->status)->toBe(VenueBooking::STATUS_CONFIRMED)
        ->and($booking->payment_status)->toBe(VenueBooking::PAYMENT_DEPOSIT_PAID)
        ->and($booking->paystack_reference)->toBe('pstk_ref_999')
        ->and($booking->paid_at)->not->toBeNull();

    // Guest confirmation mail queued
    Mail::assertQueued(BookingConfirmationGuest::class, function ($mail) use ($booking): bool {
        return $mail->hasTo($booking->planner_email);
    });
});

test('CheckAvailabilityTool in MCP returns real time-slot status when start and end times provided', function (): void {
    $payload = [
        'jsonrpc' => '2.0',
        'id' => 10,
        'method' => 'tools/call',
        'params' => [
            'name' => 'check_venue_availability',
            'arguments' => [
                'slug' => $this->hourlyListing->slug,
                'date' => Carbon::tomorrow()->toDateString(),
                'start_time' => '10:00',
                'end_time' => '16:00',
                'guest_count' => 15,
                'layout_style' => 'boardroom',
            ],
        ],
    ];

    $response = $this->postJson('/mcp/marketplace', $payload);
    $response->assertOk();

    $text = $response->json('result.content.0.text');
    $data = json_decode($text, true);

    expect($data['is_operational'])->toBeTrue()
        ->and($data['is_slot_available'])->toBeTrue()
        ->and($data['time_slot_details']['duration_units'])->toEqual(6)
        ->and($data['time_slot_details']['rental_amount_ghs'])->toEqual(3000);
});

test('RequestQuoteTool in MCP persists quote inquiry to landlord database and notifies host', function (): void {
    $payload = [
        'jsonrpc' => '2.0',
        'id' => 11,
        'method' => 'tools/call',
        'params' => [
            'name' => 'request_venue_quote',
            'arguments' => [
                'venue_slug' => $this->dailyListing->slug,
                'planner_name' => 'Kwesi Osei',
                'planner_email' => 'kwesi@accraevents.com',
                'planner_phone' => '+233 20 888 9999',
                'event_type' => 'West Africa AI Conference',
                'event_date' => Carbon::tomorrow()->toDateString(),
                'guest_count' => 300,
                'notes' => 'Requires 3-phase power and 1Gbps fiber internet link.',
            ],
        ],
    ];

    $response = $this->postJson('/mcp/marketplace', $payload);
    $response->assertOk();

    $text = $response->json('result.content.0.text');
    $data = json_decode($text, true);

    expect($data['status'])->toBe('submitted')
        ->and($data['inquiry_reference'])->toStartWith('INQ-');

    // Must be persisted in landlord DB
    $booking = VenueBooking::where('booking_reference', $data['inquiry_reference'])->first();
    expect($booking)->not->toBeNull()
        ->and($booking->planner_email)->toBe('kwesi@accraevents.com')
        ->and($booking->status)->toBe(VenueBooking::STATUS_PENDING_QUOTE);

    // Host received notification
    Mail::assertQueued(NewVenueBookingNotification::class, function ($mail): bool {
        return $mail->hasTo($this->shop->email);
    });
});

test('existing pending payment booking can be checked out via checkout route', function (): void {
    $bookingService = app(VenueBookingService::class);
    $startsAt = Carbon::tomorrow()->setTime(9, 0);
    $endsAt = Carbon::tomorrow()->setTime(17, 0);

    $booking = $bookingService->createBooking($this->hourlyListing, [
        'planner_name' => 'Fafa Agbodzi',
        'planner_email' => 'fafa@agbodzi.com',
        'event_type' => 'Strategy Retreat',
        'guest_count' => 15,
        'layout_style' => 'boardroom',
        'starts_at' => $startsAt->toDateTimeString(),
        'ends_at' => $endsAt->toDateTimeString(),
        'agreed_to_terms' => true,
    ]);

    Http::fake([
        'https://api.paystack.co/customer*' => Http::response(['data' => ['customer_code' => 'CUS_mock456', 'email' => 'fafa@agbodzi.com']], 200),
        'https://api.paystack.co/transaction/initialize' => Http::response([
            'data' => [
                'authorization_url' => 'https://checkout.paystack.com/access_code_checkout_456',
                'reference' => 'mock_paystack_ref_checkout',
            ],
        ], 200),
    ]);

    $response = $this->withHeaders(['X-Inertia' => 'true'])
        ->post(route('marketplace.bookings.checkout', $booking->booking_reference));

    $response->assertStatus(409);
    expect($response->headers->get('X-Inertia-Location'))->toBe('https://checkout.paystack.com/access_code_checkout_456');
});

test('browser callback rejects mismatched booking ID or insufficient amount', function (): void {
    $bookingService = app(VenueBookingService::class);
    $startsAt = Carbon::tomorrow()->setTime(9, 0);
    $endsAt = Carbon::tomorrow()->setTime(17, 0);

    $booking = $bookingService->createBooking($this->hourlyListing, [
        'planner_name' => 'Kojo Imposter',
        'planner_email' => 'kojo@attacker.com',
        'event_type' => 'Tampered Booking Test',
        'guest_count' => 10,
        'layout_style' => 'boardroom',
        'starts_at' => $startsAt->toDateTimeString(),
        'ends_at' => $endsAt->toDateTimeString(),
        'agreed_to_terms' => true,
    ]);

    // Paystack verification returned another booking's metadata
    Http::fake([
        'https://api.paystack.co/transaction/verify/pstk_ref_tampered' => Http::response([
            'data' => [
                'id' => 888123,
                'status' => 'success',
                'amount' => 100, // 1 cedi instead of required deposit
                'currency' => 'GHS',
                'reference' => 'pstk_ref_tampered',
                'metadata' => [
                    'booking_reference' => 'VBK-SOME-OTHER-REF',
                    'venue_booking_id' => '00000000-0000-0000-0000-000000000000',
                ],
            ],
        ], 200),
    ]);

    $response = $this->get(route('marketplace.bookings.callback', [
        'reference' => $booking->booking_reference,
        'trxref' => 'pstk_ref_tampered',
    ]));

    $response->assertRedirect(route('marketplace.bookings.show', $booking->booking_reference));

    $booking->refresh();
    expect($booking->status)->toBe(VenueBooking::STATUS_PENDING_PAYMENT)
        ->and($booking->payment_status)->toBe(VenueBooking::PAYMENT_UNPAID)
        ->and($booking->paystack_reference)->toBeNull();
});

test('expired pending payment booking releases the slot in calendar availability', function (): void {
    $service = app(VenueAvailabilityService::class);
    $bookingService = app(VenueBookingService::class);

    $startsAt = Carbon::tomorrow()->setTime(9, 0);
    $endsAt = Carbon::tomorrow()->setTime(17, 0);

    $booking = $bookingService->createBooking($this->hourlyListing, [
        'planner_name' => 'Expired Reservation',
        'planner_email' => 'expired@reserve.com',
        'event_type' => 'Expired Slot Test',
        'guest_count' => 15,
        'layout_style' => 'boardroom',
        'starts_at' => $startsAt->toDateTimeString(),
        'ends_at' => $endsAt->toDateTimeString(),
        'agreed_to_terms' => true,
    ]);

    // Initially within TTL, slot is blocked
    expect($service->isSlotAvailable($this->hourlyListing, $startsAt, $endsAt))->toBeFalse();

    // Expire the quote / hold TTL
    $booking->update(['quote_valid_until' => now()->subMinutes(10)]);

    // Now slot must be released and available
    expect($service->isSlotAvailable($this->hourlyListing, $startsAt, $endsAt))->toBeTrue();
});

test('a venue deposit carries the buyer service fee, and the payment books the venue commission', function (): void {
    $service = app(VenueBookingService::class);
    $booking = $service->createBooking($this->hourlyListing, [
        'planner_name' => 'Kofi Planner',
        'planner_email' => 'kofi@planner.test',
        'event_type' => 'Board retreat',
        'guest_count' => 12,
        'layout_style' => 'boardroom',
        'starts_at' => Carbon::tomorrow()->setTime(9, 0)->toDateTimeString(),
        'ends_at' => Carbon::tomorrow()->setTime(17, 0)->toDateTimeString(),
        'agreed_to_terms' => true,
    ]);
    $deposit = $booking->deposit_required_pesewas;

    Http::fake([
        'https://api.paystack.co/customer*' => Http::response(['data' => ['customer_code' => 'CUS_1']], 200),
        'https://api.paystack.co/transaction/initialize' => Http::response(['data' => ['authorization_url' => 'https://checkout.test/x', 'reference' => 'r1']], 200),
    ]);
    $service->initializeDepositCheckout($booking, 'https://example.test/callback');

    // A guest (no MiConvener plan) pays the Free rate: 3%.
    $fee = (int) round($deposit * 3 / 100);
    expect($booking->fresh()->buyer_fee_pesewas)->toBe($fee);
    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'transaction/initialize')
        && (int) $request['amount'] === $deposit + $fee);

    $service->confirmBookingPayment('pstk_fee_1', [
        'amount' => $deposit + $fee,
        'fees' => 1000,
        'metadata' => ['booking_reference' => $booking->booking_reference],
    ]);

    $transaction = App\Models\LedgerTransaction::where('tenant_id', $this->hostTenant->id)
        ->where('reference', "MKT-{$booking->booking_reference}-pstk_fee_1")->sole();
    $amounts = App\Models\LedgerEntry::where('transaction_id', $transaction->id)->get()
        ->mapWithKeys(fn ($entry) => [$entry->account->code => (int) $entry->amount]);
    $commission = (int) round($deposit * 10 / 100);

    expect($amounts[App\Models\LedgerAccount::CODE_PLATFORM_REVENUE])->toBe($commission + $fee)
        ->and($amounts[App\Models\LedgerAccount::CODE_ORGANIZER_PAYABLE])->toBe($deposit - 1000 - $commission)
        ->and($booking->fresh()->payment_status)->toBe(VenueBooking::PAYMENT_DEPOSIT_PAID);
});

test('the buyer service fee follows the buyer plan: none on Growth', function (): void {
    $this->seed(Database\Seeders\EventPackageSeeder::class);
    $fees = app(App\Services\Marketplace\MarketplaceFees::class);
    $planTenant = fn (string $slug): Tenant => Tenant::factory()->create([
        'package_id' => App\Models\Package::where('slug', $slug)->firstOrFail()->id,
    ]);

    expect($fees->buyerFeePercent(null))->toBe(3.0)
        ->and($fees->buyerFeePercent($planTenant('free')))->toBe(3.0)
        ->and($fees->buyerFeePercent($planTenant('starter')))->toBe(2.0)
        ->and($fees->buyerFeePercent($planTenant('growth')))->toBe(0.0)
        ->and($fees->buyerFeeOn($planTenant('starter'), 100000))->toBe(2000);
});
