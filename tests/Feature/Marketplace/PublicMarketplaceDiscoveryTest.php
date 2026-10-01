<?php

declare(strict_types=1);

namespace Tests\Feature\Marketplace;

use App\Models\Shop;
use App\Models\StoreAmenity;
use App\Models\StoreListing;
use App\Models\StoreListingAmenity;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('public marketplace index renders featured venues and verified merchants', function (): void {
    $tenant = Tenant::factory()->create();
    $shop = Shop::create([
        'tenant_id' => $tenant->id,
        'name' => 'Labadi Beach Hotel',
        'slug' => 'labadi-beach-hotel',
        'email' => 'sales@labadibeach.com',
        'phone' => '+233244000111',
        'address' => 'La Bypass',
        'city' => 'Accra',
        'region' => 'Greater Accra',
        'verification_status' => Shop::VERIFICATION_VERIFIED,
        'is_active' => true,
    ]);

    StoreListing::create([
        'shop_id' => $shop->id,
        'listing_kind' => StoreListing::KIND_VENUE,
        'title' => 'Omanye Plenary Hall',
        'slug' => 'omanye-hall',
        'rental_price_pesewas' => 2000000,
        'pricing_model' => StoreListing::PRICING_MODEL_PER_DAY,
        'price_visibility' => StoreListing::PRICE_VISIBILITY_PUBLIC,
        'status' => StoreListing::STATUS_PUBLISHED,
    ]);

    $this->get(route('marketplace.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Public/Marketplace/Index')
            ->has('featuredVenues', 1)
            ->where('featuredVenues.0.title', 'Omanye Plenary Hall')
            ->has('featuredMerchants', 1)
            ->where('featuredMerchants.0.slug', 'labadi-beach-hotel')
        );
});

test('venue search filters by city, capacity style, and price visibility', function (): void {
    $tenant1 = Tenant::factory()->create();
    $shopAccra = Shop::create([
        'tenant_id' => $tenant1->id,
        'name' => 'Accra Luxury Hotel',
        'slug' => 'accra-luxury',
        'email' => 'a@hotel.com',
        'phone' => '111',
        'address' => 'Airport, Accra',
        'city' => 'Accra',
        'region' => 'Greater Accra',
        'is_active' => true,
    ]);

    $tenant2 = Tenant::factory()->create();
    $shopKumasi = Shop::create([
        'tenant_id' => $tenant2->id,
        'name' => 'Kumasi Royal Resort',
        'slug' => 'kumasi-royal',
        'email' => 'k@hotel.com',
        'phone' => '222',
        'address' => 'Nhyiaeso, Kumasi',
        'city' => 'Kumasi',
        'region' => 'Ashanti',
        'is_active' => true,
    ]);

    // Space 1: Accra, 500 banquet, Public price GHS 15,000
    StoreListing::create([
        'shop_id' => $shopAccra->id,
        'listing_kind' => StoreListing::KIND_VENUE,
        'title' => 'Grand Ballroom Accra',
        'slug' => 'grand-ballroom-accra',
        'capacity_breakdown' => ['banquet' => 500, 'theater' => 800],
        'rental_price_pesewas' => 1500000,
        'pricing_model' => StoreListing::PRICING_MODEL_PER_DAY,
        'price_visibility' => StoreListing::PRICE_VISIBILITY_PUBLIC,
        'status' => StoreListing::STATUS_PUBLISHED,
    ]);

    // Space 2: Kumasi, 200 banquet, On request
    StoreListing::create([
        'shop_id' => $shopKumasi->id,
        'listing_kind' => StoreListing::KIND_VENUE,
        'title' => 'Ashanti Suite Kumasi',
        'slug' => 'ashanti-suite-kumasi',
        'capacity_breakdown' => ['banquet' => 200, 'theater' => 350],
        'rental_price_pesewas' => 800000,
        'pricing_model' => StoreListing::PRICING_MODEL_PER_DAY,
        'price_visibility' => StoreListing::PRICE_VISIBILITY_ON_REQUEST,
        'status' => StoreListing::STATUS_PUBLISHED,
    ]);

    // Filter by Accra
    $this->get(route('marketplace.venues.index', ['city' => 'Accra']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Public/Marketplace/Venues/Index')
            ->has('venues.data', 1)
            ->where('venues.data.0.slug', 'grand-ballroom-accra')
        );

    // Filter by Min Banquet Capacity 400
    $this->get(route('marketplace.venues.index', ['min_capacity' => 400, 'capacity_style' => 'banquet']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Public/Marketplace/Venues/Index')
            ->has('venues.data', 1)
            ->where('venues.data.0.slug', 'grand-ballroom-accra')
        );

    // Filter by Price Visibility: on_request
    $this->get(route('marketplace.venues.index', ['price_visibility' => 'on_request']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Public/Marketplace/Venues/Index')
            ->has('venues.data', 1)
            ->where('venues.data.0.slug', 'ashanti-suite-kumasi')
        );
});

test('venue search filters by guaranteed included amenities', function (): void {
    $tenant = Tenant::factory()->create();
    $shop = Shop::create([
        'tenant_id' => $tenant->id,
        'name' => 'Atlantic Conference Center',
        'slug' => 'atlantic-center',
        'email' => 'info@atlantic.com',
        'phone' => '123',
        'address' => 'Takoradi',
        'city' => 'Takoradi',
        'region' => 'Western',
        'is_active' => true,
    ]);

    $spaceWithGenerator = StoreListing::create([
        'shop_id' => $shop->id,
        'listing_kind' => StoreListing::KIND_VENUE,
        'title' => 'Ocean View Hall',
        'slug' => 'ocean-view-hall',
        'rental_price_pesewas' => 1000000,
        'status' => StoreListing::STATUS_PUBLISHED,
    ]);

    $spaceWithoutGenerator = StoreListing::create([
        'shop_id' => $shop->id,
        'listing_kind' => StoreListing::KIND_VENUE,
        'title' => 'Terrace Garden',
        'slug' => 'terrace-garden',
        'rental_price_pesewas' => 500000,
        'status' => StoreListing::STATUS_PUBLISHED,
    ]);

    $generator = StoreAmenity::create([
        'slug' => 'standby_generator',
        'name' => 'Standby Generator',
        'category' => 'power_climate',
        'icon' => 'zap',
    ]);

    // Add generator as INCLUDED for Ocean View Hall
    StoreListingAmenity::create([
        'listing_id' => $spaceWithGenerator->id,
        'amenity_id' => $generator->id,
        'is_included' => true,
    ]);

    // Add generator as EXCLUDED for Terrace Garden
    StoreListingAmenity::create([
        'listing_id' => $spaceWithoutGenerator->id,
        'amenity_id' => $generator->id,
        'is_included' => false,
        'notes' => 'Generator must be brought by client',
    ]);

    // Search with required amenity standby_generator
    $this->get(route('marketplace.venues.index', ['amenities' => ['standby_generator']]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Public/Marketplace/Venues/Index')
            ->has('venues.data', 1)
            ->where('venues.data.0.slug', 'ocean-view-hall')
        );
});

test('venue detail page renders specs and transparent included vs excluded amenities grid', function (): void {
    $tenant = Tenant::factory()->create();
    $shop = Shop::create([
        'tenant_id' => $tenant->id,
        'name' => 'Kempinski Hotel Gold Coast City',
        'slug' => 'kempinski-accra',
        'email' => 'sales@kempinski.com',
        'phone' => '+233244999000',
        'address' => 'Gamel Abdul Nasser Ave, Accra',
        'city' => 'Accra',
        'region' => 'Greater Accra',
        'verification_status' => Shop::VERIFICATION_VERIFIED,
        'is_active' => true,
    ]);

    $listing = StoreListing::create([
        'shop_id' => $shop->id,
        'listing_kind' => StoreListing::KIND_VENUE,
        'title' => 'Grand Pavilion Ballroom',
        'slug' => 'grand-pavilion',
        'description' => 'The largest pillarless ballroom in Accra.',
        'rental_price_pesewas' => 3500000,
        'pricing_model' => StoreListing::PRICING_MODEL_PER_DAY,
        'price_visibility' => StoreListing::PRICE_VISIBILITY_PUBLIC,
        'security_deposit_pesewas' => 500000,
        'capacity_breakdown' => [
            'banquet' => 600,
            'theater' => 1000,
            'cocktail' => 1200,
            'classroom' => 400,
        ],
        'floor_area_sqm' => 850.0,
        'ceiling_height_meters' => 7.0,
        'rules_and_policies' => [
            'outside_catering' => false,
            'curfew' => '03:00 AM',
        ],
        'status' => StoreListing::STATUS_PUBLISHED,
    ]);

    $ac = StoreAmenity::create([
        'slug' => 'central_ac',
        'name' => 'Central Air Conditioning',
        'category' => 'power_climate',
        'icon' => 'wind',
    ]);

    $ledWall = StoreAmenity::create([
        'slug' => 'led_wall',
        'name' => 'LED Video Wall',
        'category' => 'av_tech',
        'icon' => 'tv',
    ]);

    StoreListingAmenity::create([
        'listing_id' => $listing->id,
        'amenity_id' => $ac->id,
        'is_included' => true,
    ]);

    StoreListingAmenity::create([
        'listing_id' => $listing->id,
        'amenity_id' => $ledWall->id,
        'is_included' => false,
        'notes' => 'In-house LED wall rental available at GHS 5,000/day',
    ]);

    $this->get(route('marketplace.venues.show', ['slug' => 'grand-pavilion']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Public/Marketplace/Venues/Show')
            ->where('venue.title', 'Grand Pavilion Ballroom')
            ->where('venue.rental_price_pesewas', 3500000)
            ->where('venue.floor_area_sqm', '850.00')
            ->has('venue.amenities', 2)
            ->where('venue.shop.name', 'Kempinski Hotel Gold Coast City')
        );
});

test('draft or inactive venue spaces return 404', function (): void {
    $tenant = Tenant::factory()->create();
    $shop = Shop::create([
        'tenant_id' => $tenant->id,
        'name' => 'Secret Venue',
        'slug' => 'secret-venue',
        'email' => 's@v.com',
        'phone' => '000',
        'address' => 'Accra',
        'city' => 'Accra',
        'region' => 'Greater Accra',
        'is_active' => true,
    ]);

    $draft = StoreListing::create([
        'shop_id' => $shop->id,
        'listing_kind' => StoreListing::KIND_VENUE,
        'title' => 'Unpublished Hall',
        'slug' => 'unpublished-hall',
        'rental_price_pesewas' => 1000000,
        'status' => StoreListing::STATUS_DRAFT,
    ]);

    $this->get(route('marketplace.venues.show', ['slug' => 'unpublished-hall']))
        ->assertNotFound();

    // Now test inactive shop
    $shop->update(['is_active' => false]);
    $draft->update(['status' => StoreListing::STATUS_PUBLISHED]);

    $this->get(route('marketplace.venues.show', ['slug' => 'unpublished-hall']))
        ->assertNotFound();
});

test('merchant storefront page displays active spaces and contact info', function (): void {
    $tenant = Tenant::factory()->create();
    $shop = Shop::create([
        'tenant_id' => $tenant->id,
        'name' => 'Ridge Royal Hotel',
        'slug' => 'ridge-royal',
        'description' => 'Historic hotel overlooking Cape Coast.',
        'email' => 'sales@ridgeroyal.com',
        'phone' => '+233332111000',
        'address' => 'Second Ridge, Cape Coast',
        'city' => 'Cape Coast',
        'region' => 'Central',
        'verification_status' => Shop::VERIFICATION_VERIFIED,
        'is_active' => true,
    ]);

    StoreListing::create([
        'shop_id' => $shop->id,
        'listing_kind' => StoreListing::KIND_VENUE,
        'title' => 'Cape Coast Plenary Hall',
        'slug' => 'cape-coast-plenary',
        'rental_price_pesewas' => 1200000,
        'status' => StoreListing::STATUS_PUBLISHED,
    ]);

    $this->get(route('marketplace.storefront.show', ['merchant_slug' => 'ridge-royal']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Public/Marketplace/Storefront/Show')
            ->where('shop.name', 'Ridge Royal Hotel')
            ->where('shop.city', 'Cape Coast')
            ->has('spaces', 1)
            ->where('spaces.0.slug', 'cape-coast-plenary')
        );

    // Inactive shop returns 404
    $shop->update(['is_active' => false]);
    $this->get(route('marketplace.storefront.show', ['merchant_slug' => 'ridge-royal']))
        ->assertNotFound();
});
