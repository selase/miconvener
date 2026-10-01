<?php

declare(strict_types=1);

use App\Models\Shop;
use App\Models\StoreListing;
use App\Models\Tenant;
use App\Services\Marketplace\VenueEmbeddingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function createMcpTestShop(array $attributes = []): Shop
{
    return Shop::create(array_merge([
        'tenant_id' => Tenant::factory()->create()->id,
        'name' => 'Labadi Beach Hotel',
        'slug' => 'shop-'.Str::random(8),
        'email' => 'sales@example.com',
        'phone' => '+233244123456',
        'address' => 'No 1 La Bypass',
        'city' => 'Accra',
        'region' => 'Greater Accra',
        'is_active' => true,
    ], $attributes));
}

test('mcp server handles initialize protocol method', function (): void {
    $payload = [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'initialize',
        'params' => [
            'protocolVersion' => '2024-11-05',
            'clientInfo' => ['name' => 'test-agent', 'version' => '1.0'],
            'capabilities' => [],
        ],
    ];

    $response = $this->postJson('/mcp/marketplace', $payload);

    $response->assertOk()
        ->assertHeader('X-RateLimit-Limit', '60')
        ->assertJsonPath('jsonrpc', '2.0')
        ->assertJsonPath('id', 1)
        ->assertJsonPath('result.serverInfo.name', 'MiConvener Marketplace Concierge')
        ->assertJsonPath('result.capabilities.tools.listChanged', false);
});

test('mcp server lists all 4 venue concierge tools', function (): void {
    $payload = [
        'jsonrpc' => '2.0',
        'id' => 2,
        'method' => 'tools/list',
        'params' => [],
    ];

    $response = $this->postJson('/mcp/marketplace', $payload);

    $response->assertOk()
        ->assertJsonPath('jsonrpc', '2.0')
        ->assertJsonPath('id', 2);

    $tools = $response->json('result.tools');
    $toolNames = array_column($tools, 'name');

    expect($toolNames)->toContain('search_venues')
        ->toContain('get_venue_details')
        ->toContain('check_venue_availability')
        ->toContain('request_venue_quote');
});

test('mcp search_venues tool returns matching spaces with structured pricing and url', function (): void {
    $shop = createMcpTestShop([
        'name' => 'Labadi Beach Hotel',
        'city' => 'Accra',
        'verification_status' => Shop::VERIFICATION_VERIFIED,
    ]);

    $space = StoreListing::create([
        'shop_id' => $shop->id,
        'listing_kind' => StoreListing::KIND_VENUE,
        'title' => 'Palm Court Ocean Suite',
        'slug' => 'palm-court-ocean-suite',
        'rental_price_pesewas' => 1200000,
        'pricing_model' => StoreListing::PRICING_MODEL_PER_DAY,
        'capacity_breakdown' => ['banquet' => 120],
        'status' => StoreListing::STATUS_PUBLISHED,
    ]);
    (new VenueEmbeddingService)->embedListing($space);

    $payload = [
        'jsonrpc' => '2.0',
        'id' => 3,
        'method' => 'tools/call',
        'params' => [
            'name' => 'search_venues',
            'arguments' => [
                'city' => 'Accra',
                'min_capacity' => 100,
            ],
        ],
    ];

    $response = $this->postJson('/mcp/marketplace', $payload);

    $response->assertOk()
        ->assertJsonPath('result.isError', false);

    $contentJson = $response->json('result.content.0.text');
    $data = json_decode($contentJson, true);

    expect($data['total_found'])->toBe(1)
        ->and($data['venues'][0]['slug'])->toBe('palm-court-ocean-suite')
        ->and($data['venues'][0]['pricing']['price_ghs'])->toBe(12000);
});

test('mcp get_venue_details tool returns space specifications and included amenities', function (): void {
    $shop = createMcpTestShop([
        'name' => 'Kempinski Hotel Gold Coast City',
        'city' => 'Accra',
        'email' => 'banquets@kempinski.com',
        'phone' => '+233244111222',
        'address' => 'Ministries, Accra',
    ]);

    $space = StoreListing::create([
        'shop_id' => $shop->id,
        'listing_kind' => StoreListing::KIND_VENUE,
        'title' => 'The Grand Pavilion Ballroom',
        'slug' => 'grand-pavilion-ballroom',
        'rental_price_pesewas' => 4500000,
        'floor_area_sqm' => 1100.00,
        'status' => StoreListing::STATUS_PUBLISHED,
    ]);

    $payload = [
        'jsonrpc' => '2.0',
        'id' => 4,
        'method' => 'tools/call',
        'params' => [
            'name' => 'get_venue_details',
            'arguments' => [
                'slug' => 'grand-pavilion-ballroom',
            ],
        ],
    ];

    $response = $this->postJson('/mcp/marketplace', $payload);

    $response->assertOk()
        ->assertJsonPath('result.isError', false);

    $contentJson = $response->json('result.content.0.text');
    $data = json_decode($contentJson, true);

    expect($data['title'])->toBe('The Grand Pavilion Ballroom')
        ->and($data['host']['email'])->toBe('banquets@kempinski.com')
        ->and($data['pricing']['rental_price_ghs'])->toBe(45000);
});

test('mcp check_venue_availability detects capacity overflow', function (): void {
    $shop = createMcpTestShop([
        'name' => 'Labadi Beach Hotel',
        'city' => 'Accra',
        'address' => 'No 1 La Bypass',
    ]);

    $space = StoreListing::create([
        'shop_id' => $shop->id,
        'listing_kind' => StoreListing::KIND_VENUE,
        'title' => 'Executive Ocean Boardroom',
        'slug' => 'executive-ocean-boardroom',
        'capacity_breakdown' => ['banquet' => 20],
        'status' => StoreListing::STATUS_PUBLISHED,
    ]);

    $payload = [
        'jsonrpc' => '2.0',
        'id' => 5,
        'method' => 'tools/call',
        'params' => [
            'name' => 'check_venue_availability',
            'arguments' => [
                'slug' => 'executive-ocean-boardroom',
                'date' => '2026-11-20',
                'guest_count' => 50,
                'layout_style' => 'banquet',
            ],
        ],
    ];

    $response = $this->postJson('/mcp/marketplace', $payload);

    $response->assertOk();
    $data = json_decode($response->json('result.content.0.text'), true);

    expect($data['capacity_check']['status'])->toBe('exceeded')
        ->and($data['capacity_check']['max_capacity'])->toBe(20)
        ->and($data['capacity_check']['requested_guests'])->toBe(50);
});

test('mcp request_venue_quote formats structured booking inquiry', function (): void {
    $shop = createMcpTestShop([
        'name' => 'Ridge Royal Hotel',
        'city' => 'Cape Coast',
        'email' => 'sales@ridgeroyalhotel.com',
        'phone' => '+233244999888',
        'address' => 'Second Ridge',
    ]);

    $space = StoreListing::create([
        'shop_id' => $shop->id,
        'listing_kind' => StoreListing::KIND_VENUE,
        'title' => 'Royal Heritage Plenary',
        'slug' => 'royal-heritage-plenary',
        'rental_price_pesewas' => 1400000,
        'status' => StoreListing::STATUS_PUBLISHED,
    ]);

    $payload = [
        'jsonrpc' => '2.0',
        'id' => 6,
        'method' => 'tools/call',
        'params' => [
            'name' => 'request_venue_quote',
            'arguments' => [
                'venue_slug' => 'royal-heritage-plenary',
                'planner_name' => 'Dr. Kwame Boateng',
                'planner_email' => 'kwame@ghanahealth.org',
                'event_type' => 'Medical Summit',
                'event_date' => '2026-12-15',
                'guest_count' => 250,
                'notes' => 'Requires simultaneous interpretation booths',
            ],
        ],
    ];

    $response = $this->postJson('/mcp/marketplace', $payload);

    $response->assertOk();
    $data = json_decode($response->json('result.content.0.text'), true);

    expect($data['status'])->toBe('prepared')
        ->and($data['inquiry_reference'])->toStartWith('INQ-')
        ->and($data['estimated_costs']['space_rate_ghs'])->toBe(14000)
        ->and($data['venue']['host_email'])->toBe('sales@ridgeroyalhotel.com');
});

test('mcp get_venue_details rejects draft venue listings', function (): void {
    $shop = createMcpTestShop();
    $space = StoreListing::create([
        'shop_id' => $shop->id,
        'listing_kind' => StoreListing::KIND_VENUE,
        'title' => 'Secret Ballroom Draft',
        'slug' => 'secret-ballroom-draft',
        'status' => StoreListing::STATUS_DRAFT,
    ]);

    $payload = [
        'jsonrpc' => '2.0',
        'id' => 7,
        'method' => 'tools/call',
        'params' => [
            'name' => 'get_venue_details',
            'arguments' => ['slug' => 'secret-ballroom-draft'],
        ],
    ];

    $response = $this->postJson('/mcp/marketplace', $payload);
    $response->assertOk()
        ->assertJsonPath('result.isError', true);
});

test('mcp tools reject venue spaces belonging to inactive shops', function (): void {
    $shop = createMcpTestShop(['is_active' => false]);
    $space = StoreListing::create([
        'shop_id' => $shop->id,
        'listing_kind' => StoreListing::KIND_VENUE,
        'title' => 'Suspended Space',
        'slug' => 'suspended-space',
        'status' => StoreListing::STATUS_PUBLISHED,
    ]);

    $payload = [
        'jsonrpc' => '2.0',
        'id' => 8,
        'method' => 'tools/call',
        'params' => [
            'name' => 'check_venue_availability',
            'arguments' => ['slug' => 'suspended-space'],
        ],
    ];

    $response = $this->postJson('/mcp/marketplace', $payload);
    $response->assertOk()
        ->assertJsonPath('result.isError', true);
});

test('mcp request_venue_quote protects confidential price on request', function (): void {
    $shop = createMcpTestShop();
    $space = StoreListing::create([
        'shop_id' => $shop->id,
        'listing_kind' => StoreListing::KIND_VENUE,
        'title' => 'Diplomatic Presidential Suite',
        'slug' => 'diplomatic-presidential-suite',
        'rental_price_pesewas' => 9900000,
        'price_visibility' => StoreListing::PRICE_VISIBILITY_ON_REQUEST,
        'status' => StoreListing::STATUS_PUBLISHED,
    ]);

    $payload = [
        'jsonrpc' => '2.0',
        'id' => 9,
        'method' => 'tools/call',
        'params' => [
            'name' => 'request_venue_quote',
            'arguments' => [
                'venue_slug' => 'diplomatic-presidential-suite',
                'planner_name' => 'Diplomatic Protocol',
                'planner_email' => 'protocol@embassy.gov',
                'event_type' => 'State Banquet',
                'event_date' => '2026-12-20',
                'guest_count' => 80,
            ],
        ],
    ];

    $response = $this->postJson('/mcp/marketplace', $payload);
    $response->assertOk();
    $data = json_decode($response->json('result.content.0.text'), true);

    expect($data['estimated_costs']['space_rate_ghs'])->toBe('Price on request')
        ->and($data['estimated_costs']['is_price_on_request'])->toBeTrue();
});

test('mcp tools return isError for non-existent venue slug', function (): void {
    $payload = [
        'jsonrpc' => '2.0',
        'id' => 10,
        'method' => 'tools/call',
        'params' => [
            'name' => 'get_venue_details',
            'arguments' => ['slug' => 'completely-fictional-space'],
        ],
    ];

    $response = $this->postJson('/mcp/marketplace', $payload);
    $response->assertOk()
        ->assertJsonPath('result.isError', true);
});

test('mcp endpoint renders interactive explorer page when accessed via browser', function (): void {
    $response = $this->get('/mcp/marketplace', [
        'Accept' => 'text/html,application/xhtml+xml',
    ]);

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Public/Marketplace/Mcp/Index')
            ->has('server')
            ->has('tools', 4)
            ->where('server.name', 'MiConvener Marketplace Concierge')
        );
});

test('mcp endpoint returns 405 with Allow POST header for non-browser GET requests', function (): void {
    $response = $this->get('/mcp/marketplace', [
        'Accept' => 'application/json',
    ]);

    $response->assertStatus(405)
        ->assertHeader('Allow', 'POST');
});

test('partner api key receives elevated rate limit of 300 requests per minute', function (): void {
    $tenant = Tenant::factory()->create([
        'status' => App\Enum\TenantStatusEnum::ACTIVE,
    ]);

    $apiKeyData = app(App\Services\Api\ApiKeyService::class)->generate(
        $tenant->id,
        null,
        'Corporate Partner Key'
    );

    $payload = [
        'jsonrpc' => '2.0',
        'id' => 11,
        'method' => 'initialize',
        'params' => [
            'protocolVersion' => '2024-11-05',
            'clientInfo' => ['name' => 'partner-agent', 'version' => '1.0'],
            'capabilities' => [],
        ],
    ];

    $response = $this->postJson('/mcp/marketplace', $payload, [
        'X-Api-Key' => $apiKeyData['key'],
    ]);

    $response->assertOk()
        ->assertHeader('X-RateLimit-Limit', '300')
        ->assertJsonPath('result.serverInfo.name', 'MiConvener Marketplace Concierge');
});

test('partner api key works via Authorization Bearer header', function (): void {
    $tenant = Tenant::factory()->create([
        'status' => App\Enum\TenantStatusEnum::ACTIVE,
    ]);

    $apiKeyData = app(App\Services\Api\ApiKeyService::class)->generate(
        $tenant->id,
        null,
        'Bearer Partner Key'
    );

    $payload = [
        'jsonrpc' => '2.0',
        'id' => 12,
        'method' => 'tools/list',
        'params' => [],
    ];

    $response = $this->postJson('/mcp/marketplace', $payload, [
        'Authorization' => 'Bearer '.$apiKeyData['key'],
    ]);

    $response->assertOk()
        ->assertHeader('X-RateLimit-Limit', '300')
        ->assertJsonCount(4, 'result.tools');
});

test('invalid partner api key returns 401 unauthorized', function (): void {
    $payload = [
        'jsonrpc' => '2.0',
        'id' => 13,
        'method' => 'tools/list',
        'params' => [],
    ];

    $response = $this->postJson('/mcp/marketplace', $payload, [
        'X-Api-Key' => 'sk_fictional_invalid_key_12345678901234567890',
    ]);

    $response->assertStatus(401)
        ->assertJsonPath('error', 'Unauthorized');
});

test('revoked partner api key returns 401 unauthorized', function (): void {
    $tenant = Tenant::factory()->create([
        'status' => App\Enum\TenantStatusEnum::ACTIVE,
    ]);

    $apiKeyData = app(App\Services\Api\ApiKeyService::class)->generate(
        $tenant->id,
        null,
        'Revoked Key'
    );

    $apiKeyData['model']->update(['revoked_at' => now()->subMinute()]);

    $payload = [
        'jsonrpc' => '2.0',
        'id' => 14,
        'method' => 'tools/list',
        'params' => [],
    ];

    $response = $this->postJson('/mcp/marketplace', $payload, [
        'X-Api-Key' => $apiKeyData['key'],
    ]);

    $response->assertStatus(401)
        ->assertJsonPath('error', 'Unauthorized');
});

test('partner api key with ip restrictions rejects unauthorized ip', function (): void {
    $tenant = Tenant::factory()->create([
        'status' => App\Enum\TenantStatusEnum::ACTIVE,
    ]);

    $apiKeyData = app(App\Services\Api\ApiKeyService::class)->generate(
        $tenant->id,
        null,
        'Restricted IP Key',
        null,
        ['192.168.1.100']
    );

    $payload = [
        'jsonrpc' => '2.0',
        'id' => 15,
        'method' => 'tools/list',
        'params' => [],
    ];

    $response = $this->postJson('/mcp/marketplace', $payload, [
        'X-Api-Key' => $apiKeyData['key'],
    ]);

    $response->assertStatus(403)
        ->assertJsonPath('error', 'Forbidden')
        ->assertJsonPath('message', 'IP address not allowed for this API Key.');
});

test('tenant allowed_ips restrictions reject unauthorized ip', function (): void {
    $tenant = Tenant::factory()->create([
        'status' => App\Enum\TenantStatusEnum::ACTIVE,
        'allowed_ips' => ['10.0.0.50'],
    ]);

    $apiKeyData = app(App\Services\Api\ApiKeyService::class)->generate(
        $tenant->id,
        null,
        'Tenant IP Restricted Key'
    );

    $payload = [
        'jsonrpc' => '2.0',
        'id' => 16,
        'method' => 'tools/list',
        'params' => [],
    ];

    $response = $this->postJson('/mcp/marketplace', $payload, [
        'X-Api-Key' => $apiKeyData['key'],
    ]);

    $response->assertStatus(403)
        ->assertJsonPath('error', 'Forbidden')
        ->assertJsonPath('message', 'Tenant IP restriction: IP address not allowed.');
});

test('deactivated tenant api key returns 403 forbidden', function (): void {
    $tenant = Tenant::factory()->create([
        'status' => App\Enum\TenantStatusEnum::DEACTIVATED,
    ]);

    $apiKeyData = app(App\Services\Api\ApiKeyService::class)->generate(
        $tenant->id,
        null,
        'Deactivated Tenant Key'
    );

    $payload = [
        'jsonrpc' => '2.0',
        'id' => 17,
        'method' => 'tools/list',
        'params' => [],
    ];

    $response = $this->postJson('/mcp/marketplace', $payload, [
        'X-Api-Key' => $apiKeyData['key'],
    ]);

    $response->assertStatus(403)
        ->assertJsonPath('error', 'Forbidden')
        ->assertJsonPath('message', 'Tenant account is not active.');
});

test('banned tenant api key returns 403 forbidden', function (): void {
    $tenant = Tenant::factory()->create([
        'status' => App\Enum\TenantStatusEnum::BANNED,
    ]);

    $apiKeyData = app(App\Services\Api\ApiKeyService::class)->generate(
        $tenant->id,
        null,
        'Banned Tenant Key'
    );

    $payload = [
        'jsonrpc' => '2.0',
        'id' => 18,
        'method' => 'tools/list',
        'params' => [],
    ];

    $response = $this->postJson('/mcp/marketplace', $payload, [
        'X-Api-Key' => $apiKeyData['key'],
    ]);

    $response->assertStatus(403)
        ->assertJsonPath('error', 'Forbidden')
        ->assertJsonPath('message', 'Tenant account is not active.');
});

test('brute force invalid api key attempts return 429 too many requests', function (): void {
    Illuminate\Support\Facades\RateLimiter::clear('mcp_invalid_auth:127.0.0.1');

    $payload = [
        'jsonrpc' => '2.0',
        'id' => 19,
        'method' => 'tools/list',
        'params' => [],
    ];

    // Simulate 20 failed attempts
    for ($i = 0; $i < 20; $i++) {
        Illuminate\Support\Facades\RateLimiter::hit('mcp_invalid_auth:127.0.0.1', 60);
    }

    $response = $this->postJson('/mcp/marketplace', $payload, [
        'X-Api-Key' => 'sk_bruteforce_invalid_attempt',
    ]);

    $response->assertStatus(429)
        ->assertJsonPath('error', 'Too Many Requests')
        ->assertHeader('Retry-After');

    Illuminate\Support\Facades\RateLimiter::clear('mcp_invalid_auth:127.0.0.1');
});

test('request_venue_quote tags partner agency attribution when authenticated', function (): void {
    $tenant = Tenant::factory()->create([
        'name' => 'Apex Global Event Planners',
        'status' => App\Enum\TenantStatusEnum::ACTIVE,
    ]);

    $apiKeyData = app(App\Services\Api\ApiKeyService::class)->generate(
        $tenant->id,
        null,
        'Agency Production Key'
    );

    $shop = createMcpTestShop(['name' => 'Kempinski Hotel Gold Coast City']);
    StoreListing::create([
        'shop_id' => $shop->id,
        'listing_kind' => StoreListing::KIND_VENUE,
        'slug' => 'the-grand-ballroom',
        'title' => 'The Grand Ballroom',
        'rental_price_pesewas' => 5000000,
        'status' => StoreListing::STATUS_PUBLISHED,
    ]);

    $payload = [
        'jsonrpc' => '2.0',
        'id' => 20,
        'method' => 'tools/call',
        'params' => [
            'name' => 'request_venue_quote',
            'arguments' => [
                'venue_slug' => 'the-grand-ballroom',
                'planner_name' => 'Kojo Corporate Lead',
                'planner_email' => 'kojo@apexevents.com',
                'event_type' => 'Global Technology Summit',
                'event_date' => '2026-12-15',
                'guest_count' => 300,
            ],
        ],
    ];

    $response = $this->postJson('/mcp/marketplace', $payload, [
        'X-Api-Key' => $apiKeyData['key'],
    ]);

    $response->assertOk();
    $data = json_decode($response->json('result.content.0.text'), true);

    expect($data['partner']['is_partner_inquiry'])->toBeTrue()
        ->and($data['partner']['partner_id'])->toBe($tenant->id)
        ->and($data['partner']['partner_name'])->toBe('Apex Global Event Planners')
        ->and($data['partner']['tier'])->toBe('Corporate Agency / Planner')
        ->and($data['partner']['api_key_name'])->toBe('Agency Production Key')
        ->and($data['next_steps'])->toContain('priority partner inquiry reference');
});
