<?php

declare(strict_types=1);

use App\Models\Shop;
use App\Models\StoreListing;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

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
