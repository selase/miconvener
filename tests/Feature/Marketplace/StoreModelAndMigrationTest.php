<?php

declare(strict_types=1);

use App\Models\Shop;
use App\Models\StoreAmenity;
use App\Models\StoreListing;
use App\Models\StoreListingAmenity;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('a tenant can have one merchant shop with contact and geo details', function (): void {
    $tenant = Tenant::factory()->create([
        'name' => 'Labadi Beach Hotel',
        'slug' => 'labadi-beach-hotel',
    ]);

    $shop = Shop::create([
        'tenant_id' => $tenant->id,
        'name' => 'Labadi Beach Hotel',
        'slug' => 'labadi-beach-hotel',
        'description' => '5-star luxury beachfront conference resort in Accra.',
        'email' => 'banquets@labadibeachhotel.com',
        'phone' => '+233244123456',
        'address' => 'No 1 La Bypass, Accra',
        'city' => 'Accra',
        'region' => 'Greater Accra',
        'latitude' => 5.56000000,
        'longitude' => -0.15000000,
        'verification_status' => Shop::VERIFICATION_UNVERIFIED,
        'is_active' => true,
    ]);

    expect($tenant->fresh()->shop)->not->toBeNull()
        ->and($tenant->fresh()->shop->id)->toBe($shop->id)
        ->and($shop->tenant->id)->toBe($tenant->id)
        ->and($shop->isVerified())->toBeFalse()
        ->and($shop->is_active)->toBeTrue();
});

test('a shop can publish a venue listing with capacities and minor integer pricing', function (): void {
    $tenant = Tenant::factory()->create();
    $shop = Shop::create([
        'tenant_id' => $tenant->id,
        'name' => 'Kempinski Gold Coast City',
        'slug' => 'kempinski-accra',
        'email' => 'sales@kempinski.com',
        'phone' => '+233244999888',
        'address' => 'Ministries, Accra',
        'city' => 'Accra',
        'region' => 'Greater Accra',
    ]);

    $listing = StoreListing::create([
        'shop_id' => $shop->id,
        'listing_kind' => StoreListing::KIND_VENUE,
        'title' => 'The Grand Ballroom',
        'slug' => 'the-grand-ballroom',
        'description' => 'Premier luxury ballroom for galas and conferences.',
        'rental_price_pesewas' => 2500000, // GHS 25,000.00
        'pricing_model' => StoreListing::PRICING_MODEL_PER_DAY,
        'price_visibility' => StoreListing::PRICE_VISIBILITY_PUBLIC,
        'security_deposit_pesewas' => 500000, // GHS 5,000.00
        'capacity_breakdown' => [
            'theater' => 800,
            'banquet' => 500,
            'cocktail' => 1000,
            'classroom' => 350,
        ],
        'floor_area_sqm' => 650.00,
        'ceiling_height_meters' => 6.50,
        'rules_and_policies' => [
            'outside_catering' => false,
            'curfew' => '02:00',
        ],
        'status' => StoreListing::STATUS_PUBLISHED,
    ]);

    expect($listing->rental_price_pesewas)->toBe(2500000)
        ->and($listing->capacityFor('banquet'))->toBe(500)
        ->and($listing->capacityFor('theater'))->toBe(800)
        ->and($listing->isPublished())->toBeTrue()
        ->and($listing->isPriceOnRequest())->toBeFalse()
        ->and($shop->listings()->count())->toBe(1);
});

test('venue listings enforce the included vs excluded amenities grid', function (): void {
    $tenant = Tenant::factory()->create();
    $shop = Shop::create([
        'tenant_id' => $tenant->id,
        'name' => 'Accra International Conference Centre',
        'slug' => 'aicc',
        'email' => 'info@aicc.gov.gh',
        'phone' => '+233302123456',
        'address' => 'Castle Road, Accra',
        'city' => 'Accra',
        'region' => 'Greater Accra',
    ]);

    $listing = StoreListing::create([
        'shop_id' => $shop->id,
        'listing_kind' => StoreListing::KIND_VENUE,
        'title' => 'Main Plenary Auditorium',
        'slug' => 'main-plenary',
        'rental_price_pesewas' => 3000000,
        'status' => StoreListing::STATUS_PUBLISHED,
    ]);

    $generator = StoreAmenity::where('slug', 'generator_standby')->first()
        ?? StoreAmenity::create(['slug' => 'generator_standby', 'name' => 'Standby Generator', 'category' => 'power_climate', 'icon' => 'zap']);

    $ac = StoreAmenity::where('slug', 'central_ac')->first()
        ?? StoreAmenity::create(['slug' => 'central_ac', 'name' => 'Central AC', 'category' => 'power_climate', 'icon' => 'wind']);

    $projector = StoreAmenity::where('slug', 'projector_screens')->first()
        ?? StoreAmenity::create(['slug' => 'projector_screens', 'name' => 'Projector & Screens', 'category' => 'av_tech', 'icon' => 'projector']);

    // Generator is INCLUDED
    StoreListingAmenity::create([
        'listing_id' => $listing->id,
        'amenity_id' => $generator->id,
        'is_included' => true,
        'notes' => 'Automatic changeover within 10 seconds',
    ]);

    // Central AC is INCLUDED
    StoreListingAmenity::create([
        'listing_id' => $listing->id,
        'amenity_id' => $ac->id,
        'is_included' => true,
    ]);

    // Projector is EXCLUDED (must be rented or brought externally)
    StoreListingAmenity::create([
        'listing_id' => $listing->id,
        'amenity_id' => $projector->id,
        'is_included' => false,
        'notes' => 'Available for in-house rental at GHS 3,500/day',
    ]);

    expect($listing->amenities()->count())->toBe(3)
        ->and($listing->amenities()->included()->count())->toBe(2)
        ->and($listing->amenities()->excluded()->count())->toBe(1);

    $excludedAmenity = $listing->amenities()->excluded()->first();
    expect($excludedAmenity->amenity->slug)->toBe('projector_screens')
        ->and($excludedAmenity->notes)->toContain('GHS 3,500/day');
});

test('store listings filter by minimum capacity across seating styles', function (): void {
    $tenant = Tenant::factory()->create();
    $shop = Shop::create([
        'tenant_id' => $tenant->id,
        'name' => 'University Conference Center',
        'slug' => 'univ-center',
        'email' => 'events@univ.edu.gh',
        'phone' => '+233200111222',
        'address' => 'Legon, Accra',
        'city' => 'Accra',
        'region' => 'Greater Accra',
    ]);

    // Hall A: 200 banquet, 400 theater
    StoreListing::create([
        'shop_id' => $shop->id,
        'listing_kind' => StoreListing::KIND_VENUE,
        'title' => 'Hall A',
        'slug' => 'hall-a',
        'capacity_breakdown' => ['banquet' => 200, 'theater' => 400],
        'status' => StoreListing::STATUS_PUBLISHED,
    ]);

    // Hall B: 500 banquet, 800 theater
    StoreListing::create([
        'shop_id' => $shop->id,
        'listing_kind' => StoreListing::KIND_VENUE,
        'title' => 'Hall B',
        'slug' => 'hall-b',
        'capacity_breakdown' => ['banquet' => 500, 'theater' => 800],
        'status' => StoreListing::STATUS_PUBLISHED,
    ]);

    // Search for banquet >= 300
    $largeBanquet = StoreListing::venues()->minCapacity(300, 'banquet')->get();
    expect($largeBanquet)->toHaveCount(1)
        ->and($largeBanquet->first()->slug)->toBe('hall-b');

    // Search for theater >= 350
    $largeTheater = StoreListing::venues()->minCapacity(350, 'theater')->get();
    expect($largeTheater)->toHaveCount(2);
});

test('haversine proximity search returns venues ordered by distance within radius', function (): void {
    $tenant1 = Tenant::factory()->create();
    $shopAccra = Shop::create([
        'tenant_id' => $tenant1->id,
        'name' => 'Airport View Hotel',
        'slug' => 'airport-view',
        'email' => 'sales@airportview.com',
        'phone' => '+233244000111',
        'address' => 'Airport Residential, Accra',
        'city' => 'Accra',
        'region' => 'Greater Accra',
        'latitude' => 5.60200000,
        'longitude' => -0.17700000,
    ]);

    $venueNear = StoreListing::create([
        'shop_id' => $shopAccra->id,
        'listing_kind' => StoreListing::KIND_VENUE,
        'title' => 'Airport Conference Suite',
        'slug' => 'airport-suite',
        'status' => StoreListing::STATUS_PUBLISHED,
    ]);

    $tenant2 = Tenant::factory()->create();
    $shopKumasi = Shop::create([
        'tenant_id' => $tenant2->id,
        'name' => 'Golden Tulip Kumasi City',
        'slug' => 'golden-tulip-kumasi',
        'email' => 'sales@goldentulipkumasi.com',
        'phone' => '+233322000222',
        'address' => 'Nhyiaeso, Kumasi',
        'city' => 'Kumasi',
        'region' => 'Ashanti',
        'latitude' => 6.68800000,
        'longitude' => -1.63200000,
    ]);

    $venueFar = StoreListing::create([
        'shop_id' => $shopKumasi->id,
        'listing_kind' => StoreListing::KIND_VENUE,
        'title' => 'Asante Ballroom',
        'slug' => 'asante-ballroom',
        'status' => StoreListing::STATUS_PUBLISHED,
    ]);

    // Search near Kotoka Airport (Accra: 5.6050, -0.1700) within 15 km
    $results = StoreListing::published()->venues()->nearLocation(5.6050, -0.1700, 15.0)->get();

    expect($results)->toHaveCount(1)
        ->and($results->first()->id)->toBe($venueNear->id)
        ->and((float) $results->first()->distance_km)->toBeLessThan(2.0);
});
