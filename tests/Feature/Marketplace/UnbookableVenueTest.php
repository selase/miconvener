<?php

declare(strict_types=1);

use App\Models\Shop;
use App\Models\StoreListing;
use App\Models\Tenant;
use App\Services\Marketplace\MarketplaceVendorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

test('unbookable venues accept enquiries but cannot take quote payments', function (string $entry): void {
    Mail::fake();
    Http::fake();
    $service = app(MarketplaceVendorService::class);
    $this->post(route('marketplace.quotes.rfq', $this->unbookableListing->slug), [
        'planner_name' => 'Test Planner',
        'planner_email' => 'planner@example.test',
        'requirements_description' => 'A venue enquiry without a reservation.',
    ])->assertRedirect();
    $quote = App\Models\MarketplaceQuote::query()->firstOrFail();
    $service->submitProposal($quote, [
        'items' => [['description' => 'Venue hire', 'quantity' => 1, 'unit_price_pesewas' => 100000]],
    ]);
    $quote = $quote->fresh();
    $this->get(route('marketplace.quotes.show', $quote->quote_reference))
        ->assertOk()->assertInertia(fn ($page) => $page->where('quote.venue_payments_paused', true));

    if ($entry === 'route') {
        $this->post(route('marketplace.quotes.checkout', $quote->quote_reference))
            ->assertRedirect(route('marketplace.quotes.show', $quote->quote_reference))
            ->assertSessionHas('error', 'Online payments are paused for this venue. You can still discuss your enquiry with the host.');
    } else {
        expect(fn () => $service->initializeCheckout($quote, 'https://example.test/callback'))
            ->toThrow(RuntimeException::class, 'Online payments are paused for this venue.');
    }
    Http::assertNothingSent();
    expect($quote->fresh()->buyer_fee_pesewas)->toBe(0)
        ->and($quote->fresh()->paid_at)->toBeNull();
})->with(['route', 'service']);

test('venue quote payment eligibility follows the current booking setting', function (): void {
    Mail::fake();
    $quote = app(MarketplaceVendorService::class)->createRfq($this->unbookableListing, [
        'planner_name' => 'Test Planner',
        'planner_email' => 'planner@example.test',
        'requirements_description' => 'Venue enquiry.',
    ]);
    $this->unbookableListing->update(['is_bookable' => true]);
    $this->get(route('marketplace.quotes.show', $quote->quote_reference))
        ->assertOk()->assertInertia(fn ($page) => $page->where('quote.venue_payments_paused', false));
    $this->unbookableListing->update(['is_bookable' => false]);
    $this->get(route('marketplace.quotes.show', $quote->quote_reference))
        ->assertOk()->assertInertia(fn ($page) => $page->where('quote.venue_payments_paused', true));
});

test('paused venue proposal emails explain that payments cannot confirm a reservation', function (): void {
    Mail::fake();
    $service = app(MarketplaceVendorService::class);
    $quote = $service->createRfq($this->unbookableListing, [
        'planner_name' => 'Test Planner',
        'planner_email' => 'planner@example.test',
        'requirements_description' => 'Venue enquiry.',
    ]);
    $service->submitProposal($quote, [
        'items' => [['description' => 'Venue hire', 'quantity' => 1, 'unit_price_pesewas' => 100000]],
    ]);
    $html = (new App\Mail\Marketplace\QuoteProposalReady($quote->fresh()->load(['shop', 'listing']), 'https://example.test/quote'))->render();
    expect(str_contains($html, 'Online reservations and payments are paused for this venue.'))->toBeTrue()
        ->and(str_contains($html, 'Deposit to confirm'))->toBeFalse();
});

test('bookable venue and non-venue quotes retain payment checkout', function (string $kind): void {
    Mail::fake();
    $this->unbookableListing->update(['listing_kind' => $kind, 'is_bookable' => $kind === StoreListing::KIND_VENUE]);
    $service = app(MarketplaceVendorService::class);
    $quote = $service->createRfq($this->unbookableListing, [
        'planner_name' => 'Test Planner', 'planner_email' => 'planner@example.test',
        'requirements_description' => 'A supported quote.',
    ]);
    $service->submitProposal($quote, [
        'items' => [['description' => 'Hire', 'quantity' => 1, 'unit_price_pesewas' => 100000]],
    ]);
    Http::fake([
        'https://api.paystack.co/customer*' => Http::response(['status' => true, 'data' => ['customer_code' => 'CUS_TEST', 'id' => 1]], 200),
        'https://api.paystack.co/transaction/initialize' => Http::response(['status' => true, 'data' => ['authorization_url' => 'https://checkout.paystack.com/test', 'access_code' => 'TEST', 'reference' => 'TEST']], 200),
    ]);
    $this->withHeaders(['X-Inertia' => 'true'])->post(route('marketplace.quotes.checkout', $quote->quote_reference))
        ->assertStatus(409)->assertHeader('X-Inertia-Location', 'https://checkout.paystack.com/test');
})->with([StoreListing::KIND_VENUE, StoreListing::KIND_EQUIPMENT]);

beforeEach(function (): void {
    $this->tenant = Tenant::create([
        'name' => 'Demo Host Tenant',
        'slug' => 'demo-host-tenant',
        'isolation_mode' => 'shared',
    ]);

    $this->shop = Shop::create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Demo Host Venue',
        'slug' => 'demo-host-venue',
        'address' => '123 Ring Road, Accra',
        'city' => 'Accra',
        'region' => 'Greater Accra',
        'email' => 'host@demo-venue.test',
        'phone' => '+233 30 111 2222',
        'is_active' => true,
    ]);

    $this->unbookableListing = StoreListing::create([
        'shop_id' => $this->shop->id,
        'listing_kind' => StoreListing::KIND_VENUE,
        'title' => 'Unbookable Demo Hall',
        'slug' => 'unbookable-demo-hall',
        'rental_price_pesewas' => 2000000,
        'pricing_model' => StoreListing::PRICING_MODEL_PER_DAY,
        'price_visibility' => StoreListing::PRICE_VISIBILITY_ON_REQUEST,
        'is_bookable' => false,
        'capacity_breakdown' => ['banquet' => 300],
        'status' => StoreListing::STATUS_PUBLISHED,
    ]);
});

test('isBookable returns false when listing has is_bookable set to false', function (): void {
    expect($this->unbookableListing->isBookable())->toBeFalse();

    $bookableListing = StoreListing::create([
        'shop_id' => $this->shop->id,
        'listing_kind' => StoreListing::KIND_VENUE,
        'title' => 'Bookable Hall',
        'slug' => 'bookable-hall',
        'rental_price_pesewas' => 1500000,
        'pricing_model' => StoreListing::PRICING_MODEL_PER_DAY,
        'price_visibility' => StoreListing::PRICE_VISIBILITY_PUBLIC,
        'is_bookable' => true,
        'capacity_breakdown' => ['banquet' => 200],
        'status' => StoreListing::STATUS_PUBLISHED,
    ]);

    expect($bookableListing->isBookable())->toBeTrue();
});

test('checkAvailability endpoint returns unavailable for unbookable venue space', function (): void {
    $response = $this->postJson("/marketplace/venues/{$this->unbookableListing->slug}/check-availability", [
        'starts_at' => now()->addDays(5)->setTime(9, 0)->toDateTimeString(),
        'ends_at' => now()->addDays(5)->setTime(17, 0)->toDateTimeString(),
        'guest_count' => 100,
        'layout_style' => 'banquet',
    ]);

    $response->assertOk()
        ->assertJson([
            'available' => false,
            'is_bookable' => false,
            'pricing' => null,
        ]);
});

test('book endpoint rejects reservations for unbookable venue spaces', function (): void {
    $response = $this->post("/marketplace/venues/{$this->unbookableListing->slug}/book", [
        'starts_at' => now()->addDays(5)->setTime(9, 0)->toDateTimeString(),
        'ends_at' => now()->addDays(5)->setTime(17, 0)->toDateTimeString(),
        'guest_count' => 100,
        'layout_style' => 'banquet',
        'event_type' => 'Corporate Gala',
        'planner_name' => 'Akua Mensah',
        'planner_email' => 'akua@example.com',
        'agreed_to_terms' => true,
    ]);

    $response->assertSessionHasErrors(['starts_at']);
});

test('mcp check_venue_availability reports unbookable status', function (): void {
    $response = $this->postJson('/mcp/marketplace', [
        'jsonrpc' => '2.0',
        'id' => 10,
        'method' => 'tools/call',
        'params' => [
            'name' => 'check_venue_availability',
            'arguments' => [
                'slug' => $this->unbookableListing->slug,
                'date' => now()->addDays(7)->toDateString(),
                'start_time' => '09:00',
                'end_time' => '17:00',
                'guest_count' => 100,
            ],
        ],
    ]);

    $response->assertOk();
    $text = $response->json('result.content.0.text');
    $data = json_decode((string) $text, true);

    expect($data['is_slot_available'])->toBeFalse()
        ->and($data['is_bookable'])->toBeFalse()
        ->and($data['contact']['email'])->toBe('host@demo-venue.test');
});

test('mcp request_quote returns error when venue space is unbookable', function (): void {
    $response = $this->postJson('/mcp/marketplace', [
        'jsonrpc' => '2.0',
        'id' => 11,
        'method' => 'tools/call',
        'params' => [
            'name' => 'request_venue_quote',
            'arguments' => [
                'venue_slug' => $this->unbookableListing->slug,
                'event_date' => now()->addDays(14)->toDateString(),
                'guest_count' => 200,
                'event_type' => 'Annual General Meeting',
                'planner_name' => 'Kofi Mensah',
                'planner_email' => 'kofi@enterprise.test',
            ],
        ],
    ]);

    $response->assertOk()
        ->assertJsonPath('result.isError', true);
    expect($response->json('result.content.0.text'))->toContain('not currently accepting automated online quote requests');
});
