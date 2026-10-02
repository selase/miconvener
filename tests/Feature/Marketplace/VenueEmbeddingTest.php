<?php

declare(strict_types=1);

use App\Models\Shop;
use App\Models\StoreAmenity;
use App\Models\StoreListing;
use App\Models\StoreListingAmenity;
use App\Models\Tenant;
use App\Services\Marketplace\VenueEmbeddingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function createEmbeddingTestShop(array $attributes = []): Shop
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

test('VenueEmbeddingService synthesizes a rich semantic document', function (): void {
    $shop = createEmbeddingTestShop([
        'name' => 'Labadi Beach Hotel',
        'slug' => 'labadi-beach-hotel',
        'description' => 'Luxury beachfront conference resort.',
    ]);

    $listing = StoreListing::create([
        'shop_id' => $shop->id,
        'listing_kind' => StoreListing::KIND_VENUE,
        'title' => 'Palm Court Ocean Suite',
        'slug' => 'palm-court-ocean-suite',
        'description' => 'Beachfront meeting space with panoramic views.',
        'rental_price_pesewas' => 1200000,
        'pricing_model' => StoreListing::PRICING_MODEL_PER_DAY,
        'price_visibility' => StoreListing::PRICE_VISIBILITY_PUBLIC,
        'capacity_breakdown' => ['banquet' => 120, 'theater' => 180],
        'floor_area_sqm' => 220.00,
        'ceiling_height_meters' => 4.20,
        'status' => StoreListing::STATUS_PUBLISHED,
    ]);

    $amenity1 = StoreAmenity::create([
        'name' => 'Standby Generator',
        'slug' => 'standby-generator',
        'category' => 'power_climate',
        'icon' => 'zap',
    ]);

    $amenity2 = StoreAmenity::create([
        'name' => 'Sound Engineer',
        'slug' => 'sound-engineer',
        'category' => 'av_tech',
        'icon' => 'volume-2',
    ]);

    StoreListingAmenity::create([
        'listing_id' => $listing->id,
        'amenity_id' => $amenity1->id,
        'is_included' => true,
    ]);

    StoreListingAmenity::create([
        'listing_id' => $listing->id,
        'amenity_id' => $amenity2->id,
        'is_included' => false,
        'notes' => 'Available for extra fee',
    ]);

    $service = new VenueEmbeddingService;
    $document = $service->synthesizeDocument($listing);

    expect($document)->toContain('Venue Space: Palm Court Ocean Suite')
        ->toContain('Labadi Beach Hotel')
        ->toContain('Accra')
        ->toContain('Floor Area: 220')
        ->toContain('120 guests in Banquet seating')
        ->toContain('Included Amenities & Equipment: Standby Generator')
        ->toContain('Additional Paid Services / Excluded: Sound Engineer')
        ->toContain('Rental Rate: GHS 12,000.00 per day');
});

test('VenueEmbeddingService produces 1536-dimensional unit vectors', function (): void {
    $service = new VenueEmbeddingService;
    $vector = $service->generateEmbedding('Executive banquet hall with backup power in Accra');

    expect($vector)->toBeArray()
        ->and(count($vector))->toBe(VenueEmbeddingService::VECTOR_DIMENSIONS);

    // Verify L2 norm is approximately 1.0 (unit vector)
    $sumSquares = array_sum(array_map(fn ($v) => $v * $v, $vector));
    expect(round(sqrt($sumSquares), 3))->toBe(1.0);
});

test('VenueEmbeddingService computes higher similarity for semantically overlapping prompts', function (): void {
    $service = new VenueEmbeddingService;

    $promptTarget = 'Executive boardroom with generator in Accra';
    $promptRelated = 'Boardroom with backup power in Accra';
    $promptUnrelated = 'Outdoor farm campsite in Northern Ghana with tents';

    $vecTarget = $service->generateEmbedding($promptTarget);
    $vecRelated = $service->generateEmbedding($promptRelated);
    $vecUnrelated = $service->generateEmbedding($promptUnrelated);

    // Compute dot products (cosine similarity for unit vectors)
    $simRelated = array_sum(array_map(fn ($a, $b) => $a * $b, $vecTarget, $vecRelated));
    $simUnrelated = array_sum(array_map(fn ($a, $b) => $a * $b, $vecTarget, $vecUnrelated));

    expect($simRelated)->toBeGreaterThan($simUnrelated);
});

test('embedListing persists vector into PostgreSQL vector column', function (): void {
    $shop = createEmbeddingTestShop([
        'name' => 'Kempinski Hotel Gold Coast City',
        'city' => 'Accra',
    ]);

    $listing = StoreListing::create([
        'shop_id' => $shop->id,
        'listing_kind' => StoreListing::KIND_VENUE,
        'title' => 'Grand Pavilion Ballroom',
        'slug' => 'grand-pavilion-ballroom',
        'rental_price_pesewas' => 4500000,
        'status' => StoreListing::STATUS_PUBLISHED,
    ]);

    expect($listing->embedding)->toBeNull();

    $service = new VenueEmbeddingService;
    $success = $service->embedListing($listing);

    expect($success)->toBeTrue();

    $freshListing = StoreListing::find($listing->id);
    expect($freshListing->embedding)->toBeArray()
        ->and(count($freshListing->embedding))->toBe(1536);
});

test('marketplace:embed-venues command batch embeds published listings', function (): void {
    $shop = createEmbeddingTestShop([
        'name' => 'Ridge Royal Hotel',
        'city' => 'Cape Coast',
    ]);

    $space1 = StoreListing::create([
        'shop_id' => $shop->id,
        'listing_kind' => StoreListing::KIND_VENUE,
        'title' => 'Royal Heritage Plenary',
        'slug' => 'royal-heritage-plenary',
        'status' => StoreListing::STATUS_PUBLISHED,
    ]);

    $space2 = StoreListing::create([
        'shop_id' => $shop->id,
        'listing_kind' => StoreListing::KIND_VENUE,
        'title' => 'Draft Breakout Salon',
        'slug' => 'draft-breakout-salon',
        'status' => StoreListing::STATUS_DRAFT,
    ]);

    Artisan::call('marketplace:embed-venues');

    expect($space1->fresh()->embedding)->not->toBeNull()
        ->and($space2->fresh()->embedding)->toBeNull();
});
