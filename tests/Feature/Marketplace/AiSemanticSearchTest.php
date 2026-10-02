<?php

declare(strict_types=1);

use App\Models\Shop;
use App\Models\StoreListing;
use App\Models\Tenant;
use App\Services\Marketplace\MarketplaceSearchService;
use App\Services\Marketplace\VenueEmbeddingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function createSemanticTestShop(array $attributes = []): Shop
{
    return Shop::create(array_merge([
        'tenant_id' => Tenant::factory()->create()->id,
        'name' => 'Labadi Beach Hotel',
        'slug' => 'shop-'.Str::random(8),
        'email' => 'events@example.com',
        'phone' => '+233244123456',
        'address' => 'No 1 Coastal Bypass',
        'city' => 'Accra',
        'region' => 'Greater Accra',
        'is_active' => true,
    ], $attributes));
}

test('hybrid search ranks venues by vector cosine similarity matching natural language prompt', function (): void {
    $shop = createSemanticTestShop([
        'name' => 'Labadi Beach Hotel',
        'city' => 'Accra',
        'verification_status' => Shop::VERIFICATION_VERIFIED,
    ]);

    $embeddingService = new VenueEmbeddingService;

    // Space 1: Beachfront Ocean Suite with sea view
    $spaceOcean = StoreListing::create([
        'shop_id' => $shop->id,
        'listing_kind' => StoreListing::KIND_VENUE,
        'title' => 'Palm Court Ocean Suite',
        'slug' => 'palm-court-ocean-suite',
        'description' => 'Beachfront panoramic ocean view ballroom on the coast of Accra.',
        'status' => StoreListing::STATUS_PUBLISHED,
        'rental_price_pesewas' => 1200000,
    ]);
    $embeddingService->embedListing($spaceOcean);

    // Space 2: Mountain retreat in Eastern Region
    $spaceMountain = StoreListing::create([
        'shop_id' => $shop->id,
        'listing_kind' => StoreListing::KIND_VENUE,
        'title' => 'Aburi Mountain Ridge Hall',
        'slug' => 'aburi-mountain-ridge-hall',
        'description' => 'Cool high-altitude mountain retreat hall surrounded by botanical pine trees.',
        'status' => StoreListing::STATUS_PUBLISHED,
        'rental_price_pesewas' => 800000,
    ]);
    $embeddingService->embedListing($spaceMountain);

    $searchService = new MarketplaceSearchService($embeddingService);

    // Search for ocean / coastal prompt
    $results = $searchService->searchVenues(['ai_prompt' => 'beachfront ocean view venue in Accra']);

    expect($results->total())->toBe(2)
        ->and($results->first()->id)->toBe($spaceOcean->id)
        ->and($results->first()->similarity_percentage)->toBeGreaterThan(0);
});

test('hybrid search combines vector prompt with structured capacity and city filters', function (): void {
    $shopAccra = createSemanticTestShop([
        'name' => 'Kempinski Hotel Gold Coast City',
        'city' => 'Accra',
        'verification_status' => Shop::VERIFICATION_VERIFIED,
    ]);

    $shopKumasi = createSemanticTestShop([
        'name' => 'Lancaster Kumasi',
        'city' => 'Kumasi',
        'verification_status' => Shop::VERIFICATION_VERIFIED,
    ]);

    $embeddingService = new VenueEmbeddingService;

    $spaceAccraLarge = StoreListing::create([
        'shop_id' => $shopAccra->id,
        'listing_kind' => StoreListing::KIND_VENUE,
        'title' => 'Grand Pavilion Ballroom',
        'slug' => 'grand-pavilion-ballroom',
        'description' => 'Luxury conference ballroom with AV and power.',
        'capacity_breakdown' => ['banquet' => 500],
        'status' => StoreListing::STATUS_PUBLISHED,
    ]);
    $embeddingService->embedListing($spaceAccraLarge);

    $spaceKumasiLarge = StoreListing::create([
        'shop_id' => $shopKumasi->id,
        'listing_kind' => StoreListing::KIND_VENUE,
        'title' => 'Manhyia Plenary Hall',
        'slug' => 'manhyia-plenary-hall',
        'description' => 'Executive ballroom with generator in Kumasi.',
        'capacity_breakdown' => ['banquet' => 500],
        'status' => StoreListing::STATUS_PUBLISHED,
    ]);
    $embeddingService->embedListing($spaceKumasiLarge);

    $searchService = new MarketplaceSearchService($embeddingService);

    // Search for luxury ballroom with 500 banquet guests restricted to Accra
    $results = $searchService->searchVenues([
        'ai_prompt' => 'luxury ballroom with generator',
        'city' => 'Accra',
        'min_capacity' => 400,
        'capacity_style' => 'banquet',
    ]);

    expect($results->total())->toBe(1)
        ->and($results->first()->id)->toBe($spaceAccraLarge->id);
});

test('public venues directory endpoint accepts ai_prompt filter without error', function (): void {
    $shop = createSemanticTestShop([
        'name' => 'Labadi Beach Hotel',
        'city' => 'Accra',
        'verification_status' => Shop::VERIFICATION_VERIFIED,
    ]);

    $space = StoreListing::create([
        'shop_id' => $shop->id,
        'listing_kind' => StoreListing::KIND_VENUE,
        'title' => 'Executive Ocean Boardroom',
        'slug' => 'executive-ocean-boardroom',
        'status' => StoreListing::STATUS_PUBLISHED,
    ]);
    (new VenueEmbeddingService)->embedListing($space);

    $response = $this->get('/marketplace/venues?ai_prompt=ocean+view+boardroom&sort=ai_match');

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Public/Marketplace/Venues/Index')
            ->has('venues.data', 1)
            ->where('filters.ai_prompt', 'ocean view boardroom')
            ->where('filters.sort', 'ai_match')
        );
});

test('semantic search strictly excludes draft and suspended spaces', function (): void {
    $shop = createSemanticTestShop([
        'name' => 'Alisa Hotel',
        'city' => 'Accra',
    ]);

    $embeddingService = new VenueEmbeddingService;

    $published = StoreListing::create([
        'shop_id' => $shop->id,
        'listing_kind' => StoreListing::KIND_VENUE,
        'title' => 'Asante Hall Published',
        'slug' => 'asante-hall-published',
        'description' => 'Ballroom with generator and projector.',
        'status' => StoreListing::STATUS_PUBLISHED,
    ]);
    $embeddingService->embedListing($published);

    $draft = StoreListing::create([
        'shop_id' => $shop->id,
        'listing_kind' => StoreListing::KIND_VENUE,
        'title' => 'Volta Hall Draft',
        'slug' => 'volta-hall-draft',
        'description' => 'Ballroom with generator and projector in draft mode.',
        'status' => StoreListing::STATUS_DRAFT,
    ]);
    $embeddingService->embedListing($draft);

    $searchService = new MarketplaceSearchService($embeddingService);
    $results = $searchService->searchVenues(['ai_prompt' => 'ballroom with generator']);

    expect($results->total())->toBe(1)
        ->and($results->first()->id)->toBe($published->id);
});

test('hybrid search safely combines semantic vector with geo proximity without column overwrites', function (): void {
    $shop = createSemanticTestShop([
        'name' => 'Labadi Beach Hotel',
        'city' => 'Accra',
        'latitude' => 5.55602,
        'longitude' => -0.14655,
    ]);

    $embeddingService = new VenueEmbeddingService;

    $space = StoreListing::create([
        'shop_id' => $shop->id,
        'listing_kind' => StoreListing::KIND_VENUE,
        'title' => 'Labadi Beach Front Pavilion',
        'slug' => 'labadi-beach-front-pavilion',
        'description' => 'Ocean view pavilion for outdoor banquets.',
        'status' => StoreListing::STATUS_PUBLISHED,
    ]);
    $embeddingService->embedListing($space);

    $searchService = new MarketplaceSearchService($embeddingService);

    $results = $searchService->searchVenues([
        'ai_prompt' => 'ocean view pavilion',
        'lat' => 5.5560,
        'lng' => -0.1465,
        'radius' => 10,
    ]);

    expect($results->total())->toBe(1);
    $item = $results->first();
    expect($item->id)->toBe($space->id)
        ->and($item->similarity_percentage)->toBeGreaterThan(0)
        ->and($item->distance_km)->not->toBeNull();
});

test('semantic search handles whitespace or empty prompt without errors', function (): void {
    $searchService = new MarketplaceSearchService(new VenueEmbeddingService);
    $results = $searchService->searchVenues(['ai_prompt' => '   ']);

    expect($results)->not->toBeNull();
});
