<?php

declare(strict_types=1);

namespace Tests\Feature\Marketplace;

use App\Models\Shop;
use App\Models\StoreAmenity;
use App\Models\StoreListing;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
});

test('user without manage venue permission cannot access venue spaces or profile', function (): void {
    $tenant = Tenant::factory()->create([
        'name' => 'Demo Tenant',
        'slug' => 'demo-tenant',
        'isolation_mode' => 'shared',
    ]);

    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    $tenant->users()->attach($user->id);
    setPermissionsTeamId($tenant->id);

    // Assign a role without 'manage venue'
    $role = Role::create([
        'uuid' => (string) \Illuminate\Support\Str::uuid(),
        'name' => 'Restricted Viewer',
        'guard_name' => 'web',
        'tenant_id' => $tenant->id,
    ]);
    $user->assignRole($role);

    $this->actingAs($user)
        ->get(route('tenant.venue.spaces.index', ['subdomain' => $tenant->slug]))
        ->assertForbidden();

    $this->actingAs($user)
        ->get(route('tenant.venue.profile', ['subdomain' => $tenant->slug]))
        ->assertForbidden();
});

test('tenant admin can update venue profile and submit for verification', function (): void {
    $user = User::factory()->create();
    $tenant = setActiveTenantForTest($user);
    $user->assignRole('Org Superadmin');

    $response = $this->actingAs($user)
        ->post(route('tenant.venue.profile.update', ['subdomain' => $tenant->slug]), [
            'name' => 'Labadi Beach Hotel',
            'slug' => 'labadi-beach-hotel',
            'description' => 'Beachfront luxury venue in Accra.',
            'email' => 'sales@labadibeach.com',
            'phone' => '+233244111222',
            'address' => '1 La Bypass, Trade Fair area',
            'city' => 'Accra',
            'region' => 'Greater Accra',
            'latitude' => 5.565,
            'longitude' => -0.155,
        ]);

    $response->assertRedirect(route('tenant.venue.profile', ['subdomain' => $tenant->slug]));

    $shop = $tenant->fresh()->shop;
    expect($shop)->not->toBeNull()
        ->and($shop->name)->toBe('Labadi Beach Hotel')
        ->and($shop->slug)->toBe('labadi-beach-hotel')
        ->and($shop->city)->toBe('Accra')
        ->and((float) $shop->latitude)->toBe(5.565)
        ->and($shop->verification_status)->toBe(Shop::VERIFICATION_UNVERIFIED);

    // Submit for verification
    $verifyResponse = $this->actingAs($user)
        ->post(route('tenant.venue.profile.verify', ['subdomain' => $tenant->slug]));

    $verifyResponse->assertRedirect(route('tenant.venue.profile', ['subdomain' => $tenant->slug]));
    expect($shop->fresh()->verification_status)->toBe(Shop::VERIFICATION_PENDING);
});

test('tenant admin can create venue space with included and excluded amenities grid', function (): void {
    $user = User::factory()->create();
    $tenant = setActiveTenantForTest($user);
    $user->assignRole('Org Superadmin');

    // Create profile
    $shop = Shop::create([
        'tenant_id' => $tenant->id,
        'name' => 'Grand Conference Hotel',
        'slug' => 'grand-conference-hotel',
        'email' => 'sales@grandconference.com',
        'phone' => '+233244000111',
        'address' => 'Airport Residential, Accra',
        'city' => 'Accra',
        'region' => 'Greater Accra',
    ]);

    $amenity1 = StoreAmenity::create([
        'slug' => 'standby_generator',
        'name' => 'Standby Generator',
        'category' => 'power_climate',
        'icon' => 'zap',
    ]);

    $amenity2 = StoreAmenity::create([
        'slug' => 'led_screen_wall',
        'name' => 'LED Video Wall',
        'category' => 'av_tech',
        'icon' => 'tv',
    ]);

    $response = $this->actingAs($user)
        ->post(route('tenant.venue.spaces.store', ['subdomain' => $tenant->slug]), [
            'title' => 'Executive Plenary Hall',
            'slug' => 'executive-plenary-hall',
            'description' => 'A fully equipped soundproof plenary hall.',
            'rental_price' => 15000.00, // GHS 15,000 -> 1,500,000 pesewas
            'pricing_model' => StoreListing::PRICING_MODEL_PER_DAY,
            'price_visibility' => StoreListing::PRICE_VISIBILITY_PUBLIC,
            'security_deposit' => 2000.00, // GHS 2,000 -> 200,000 pesewas
            'capacity_breakdown' => [
                'theater' => 500,
                'banquet' => 300,
                'cocktail' => 600,
                'classroom' => 250,
            ],
            'floor_area_sqm' => 450.5,
            'ceiling_height_meters' => 5.2,
            'rules_and_policies' => [
                'outside_catering' => false,
            ],
            'status' => StoreListing::STATUS_PUBLISHED,
            'amenities' => [
                [
                    'amenity_id' => $amenity1->id,
                    'is_included' => true,
                    'notes' => '100% automatic power backup',
                ],
                [
                    'amenity_id' => $amenity2->id,
                    'is_included' => false,
                    'notes' => 'Add-on rental: GHS 4,500/day',
                ],
            ],
        ]);

    $response->assertRedirect(route('tenant.venue.spaces.index', ['subdomain' => $tenant->slug]));

    $listing = StoreListing::where('slug', 'executive-plenary-hall')->first();
    expect($listing)->not->toBeNull()
        ->and($listing->rental_price_pesewas)->toBe(1500000)
        ->and($listing->security_deposit_pesewas)->toBe(200000)
        ->and($listing->capacityFor('banquet'))->toBe(300)
        ->and($listing->capacityFor('theater'))->toBe(500)
        ->and($listing->amenities()->count())->toBe(2)
        ->and($listing->amenities()->included()->count())->toBe(1)
        ->and($listing->amenities()->excluded()->count())->toBe(1);

    $excluded = $listing->amenities()->excluded()->first();
    expect($excluded->notes)->toBe('Add-on rental: GHS 4,500/day');
});

test('tenant admin can update and delete their venue space', function (): void {
    $user = User::factory()->create();
    $tenant = setActiveTenantForTest($user);
    $user->assignRole('Org Superadmin');

    $shop = Shop::create([
        'tenant_id' => $tenant->id,
        'name' => 'Royal Palms Resort',
        'slug' => 'royal-palms',
        'email' => 'events@royalpalms.com',
        'phone' => '+233244222333',
        'address' => 'Cape Coast Road, Takoradi',
        'city' => 'Takoradi',
        'region' => 'Western Region',
    ]);

    $listing = StoreListing::create([
        'shop_id' => $shop->id,
        'listing_kind' => StoreListing::KIND_VENUE,
        'title' => 'Ocean Suite',
        'slug' => 'ocean-suite',
        'rental_price_pesewas' => 800000,
        'pricing_model' => StoreListing::PRICING_MODEL_PER_DAY,
        'price_visibility' => StoreListing::PRICE_VISIBILITY_PUBLIC,
        'status' => StoreListing::STATUS_DRAFT,
    ]);

    // Update
    $updateResponse = $this->actingAs($user)
        ->put(route('tenant.venue.spaces.update', [
            'subdomain' => $tenant->slug,
            'listing' => $listing->id,
        ]), [
            'title' => 'Ocean Suite - Renamed',
            'slug' => 'ocean-suite',
            'rental_price' => 9500.00,
            'pricing_model' => StoreListing::PRICING_MODEL_PER_DAY,
            'price_visibility' => StoreListing::PRICE_VISIBILITY_ON_REQUEST,
            'status' => StoreListing::STATUS_PUBLISHED,
        ]);

    $updateResponse->assertRedirect(route('tenant.venue.spaces.index', ['subdomain' => $tenant->slug]));
    expect($listing->fresh()->title)->toBe('Ocean Suite - Renamed')
        ->and($listing->fresh()->rental_price_pesewas)->toBe(950000)
        ->and($listing->fresh()->price_visibility)->toBe(StoreListing::PRICE_VISIBILITY_ON_REQUEST);

    // Delete
    $deleteResponse = $this->actingAs($user)
        ->delete(route('tenant.venue.spaces.destroy', [
            'subdomain' => $tenant->slug,
            'listing' => $listing->id,
        ]));

    $deleteResponse->assertRedirect(route('tenant.venue.spaces.index', ['subdomain' => $tenant->slug]));
    expect(StoreListing::find($listing->id))->toBeNull();
});

test('cross-tenant boundary holds: tenant cannot edit or delete another tenants venue space', function (): void {
    // Tenant A
    $tenantA = Tenant::factory()->create(['slug' => 'tenant-a']);
    $shopA = Shop::create([
        'tenant_id' => $tenantA->id,
        'name' => 'Tenant A Hotel',
        'slug' => 'tenant-a-hotel',
        'email' => 'a@hotel.com',
        'phone' => '+233200000001',
        'address' => 'Accra',
        'city' => 'Accra',
        'region' => 'Greater Accra',
    ]);
    $listingA = StoreListing::create([
        'shop_id' => $shopA->id,
        'listing_kind' => StoreListing::KIND_VENUE,
        'title' => 'Space A',
        'slug' => 'space-a',
        'rental_price_pesewas' => 500000,
        'status' => StoreListing::STATUS_PUBLISHED,
    ]);

    // Tenant B
    $userB = User::factory()->create();
    $tenantB = setActiveTenantForTest($userB, ['slug' => 'tenant-b']);
    $userB->assignRole('Org Superadmin');

    // Tenant B attempts to edit or delete Space A
    $this->actingAs($userB)
        ->get(route('tenant.venue.spaces.edit', [
            'subdomain' => $tenantB->slug,
            'listing' => $listingA->id,
        ]))
        ->assertForbidden();

    $this->actingAs($userB)
        ->put(route('tenant.venue.spaces.update', [
            'subdomain' => $tenantB->slug,
            'listing' => $listingA->id,
        ]), [
            'title' => 'Hacked Space',
            'slug' => 'space-a',
            'rental_price' => 100.00,
            'pricing_model' => StoreListing::PRICING_MODEL_PER_DAY,
            'price_visibility' => StoreListing::PRICE_VISIBILITY_PUBLIC,
            'status' => StoreListing::STATUS_PUBLISHED,
        ])
        ->assertForbidden();

    $this->actingAs($userB)
        ->delete(route('tenant.venue.spaces.destroy', [
            'subdomain' => $tenantB->slug,
            'listing' => $listingA->id,
        ]))
        ->assertForbidden();

    expect(StoreListing::find($listingA->id))->not->toBeNull()
        ->and(StoreListing::find($listingA->id)->title)->toBe('Space A');
});

test('global uniqueness: two tenants cannot create a venue space with colliding slug', function (): void {
    // Tenant A creates Space with slug 'grand-ballroom'
    $tenantA = Tenant::factory()->create(['slug' => 'tenant-a-resort']);
    $shopA = Shop::create([
        'tenant_id' => $tenantA->id,
        'name' => 'Resort A',
        'slug' => 'resort-a',
        'email' => 'a@resort.com',
        'phone' => '+233200000010',
        'address' => 'Accra',
        'city' => 'Accra',
        'region' => 'Greater Accra',
    ]);
    StoreListing::create([
        'shop_id' => $shopA->id,
        'listing_kind' => StoreListing::KIND_VENUE,
        'title' => 'Grand Ballroom',
        'slug' => 'grand-ballroom',
        'rental_price_pesewas' => 500000,
        'status' => StoreListing::STATUS_PUBLISHED,
    ]);

    // Tenant B attempts to create space with identical slug 'grand-ballroom'
    $userB = User::factory()->create();
    $tenantB = setActiveTenantForTest($userB, ['slug' => 'tenant-b-hotel']);
    $userB->assignRole('Org Superadmin');
    Shop::create([
        'tenant_id' => $tenantB->id,
        'name' => 'Hotel B',
        'slug' => 'hotel-b',
        'email' => 'b@hotel.com',
        'phone' => '+233200000020',
        'address' => 'Kumasi',
        'city' => 'Kumasi',
        'region' => 'Ashanti',
    ]);

    $response = $this->actingAs($userB)
        ->post(route('tenant.venue.spaces.store', ['subdomain' => $tenantB->slug]), [
            'title' => 'Another Grand Ballroom',
            'slug' => 'grand-ballroom',
            'rental_price' => 4500.00,
            'pricing_model' => StoreListing::PRICING_MODEL_PER_DAY,
            'price_visibility' => StoreListing::PRICE_VISIBILITY_PUBLIC,
            'status' => StoreListing::STATUS_PUBLISHED,
        ]);

    $response->assertSessionHasErrors(['slug']);
});
