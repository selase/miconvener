<?php

declare(strict_types=1);

namespace Tests\Feature\Marketplace;

use App\Models\Shop;
use App\Models\StoreListing;
use App\Models\Tenant;
use App\Models\TenantAddon;
use App\Models\User;
use App\Services\Billing\TenantAddonService;
use App\Services\Marketplace\MarketplaceSearchService;
use App\Services\Tenancy\TenantContext;
use DateTimeInterface;
use Illuminate\Support\Facades\Artisan;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * What businesses pay to stand out (prices set 2026-10-05): verification
 * GHS 150 a year, a search boost GHS 50 a week, a featured spot GHS 200 a
 * month. Each is a one-off payment for its period; buying again extends it.
 */
beforeEach(function (): void {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
});

function promotedShop(string $name, string $status = Shop::VERIFICATION_UNVERIFIED): Shop
{
    $shop = Shop::factory()->create(['name' => $name, 'verification_status' => $status]);
    StoreListing::factory()->published()->create([
        'shop_id' => $shop->id,
        'title' => "{$name} Hall",
        'listing_kind' => StoreListing::KIND_VENUE,
    ]);

    return $shop;
}

function promote(Shop $shop, string $type, DateTimeInterface $until): TenantAddon
{
    return TenantAddon::factory()->create([
        'tenant_id' => $shop->tenant_id,
        'addon_type' => $type,
        'quantity' => 1,
        'billing_interval' => TenantAddon::INTERVAL_ONE_OFF,
        'status' => TenantAddon::STATUS_ACTIVE,
        'period_start' => now()->subDay(),
        'period_end' => $until,
    ]);
}

test('the three business add-ons are priced as decided and only offered to a business', function (): void {
    $catalog = collect(TenantAddonService::CATALOG);

    expect($catalog['shop_verification']['unit_price'])->toBe(15000)
        ->and($catalog['shop_verification']['billing_interval'])->toBe(TenantAddon::INTERVAL_YEARLY)
        ->and($catalog['shop_boost']['unit_price'])->toBe(5000)
        ->and($catalog['shop_boost']['billing_interval'])->toBe(TenantAddon::INTERVAL_WEEKLY)
        ->and($catalog['shop_featured']['unit_price'])->toBe(20000)
        ->and($catalog['shop_featured']['billing_interval'])->toBe(TenantAddon::INTERVAL_MONTHLY);

    $withoutShop = Tenant::factory()->create(['isolation_mode' => 'shared']);
    app(TenantContext::class)->setTenant($withoutShop);
    expect(app(TenantAddonService::class)->purchasableKeys())->not->toContain('shop_boost');

    $business = promotedShop('Grand Arena');
    app(TenantContext::class)->setTenant($business->tenant);
    expect(app(TenantAddonService::class)->purchasableKeys())->toContain('shop_boost', 'shop_featured', 'shop_verification');
});

test('a second boost starts when the first one ends', function (): void {
    $shop = promotedShop('Grand Arena');
    $service = app(TenantAddonService::class);

    $first = $service->fulfillAddonPurchase($shop->tenant, 'ref-boost-1', ['addon_key' => 'shop_boost']);
    $second = $service->fulfillAddonPurchase($shop->tenant, 'ref-boost-2', ['addon_key' => 'shop_boost']);

    expect($first->period_start->diffInDays($first->period_end))->toEqual(7)
        ->and($second->period_start->equalTo($first->period_end))->toBeTrue()
        ->and($second->period_end->equalTo($first->period_end->addWeek()))->toBeTrue();
});

test('a boosted business comes first in search', function (): void {
    promotedShop('Already Verified', Shop::VERIFICATION_VERIFIED);
    $boosted = promotedShop('Boosted Hall');
    promote($boosted, TenantAddon::TYPE_SHOP_BOOST, now()->addDays(3));

    $first = app(MarketplaceSearchService::class)->searchVenues([], 12)->items()[0];

    expect($first->shop_id)->toBe($boosted->id);
});

test('a boost that has ended no longer lifts a business', function (): void {
    $verified = promotedShop('Already Verified', Shop::VERIFICATION_VERIFIED);
    $lapsed = promotedShop('Lapsed Boost');
    promote($lapsed, TenantAddon::TYPE_SHOP_BOOST, now()->subDay());

    expect(app(MarketplaceSearchService::class)->searchVenues([], 12)->items()[0]->shop_id)->toBe($verified->id);
});

test('a featured business leads the marketplace home and is marked featured', function (): void {
    promotedShop('Already Verified', Shop::VERIFICATION_VERIFIED);
    $featured = promotedShop('Featured Hall');
    promote($featured, TenantAddon::TYPE_SHOP_FEATURED, now()->addWeeks(2));

    $this->get(route('marketplace.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('featuredVenues.0.shop_id', $featured->id)
            ->where('featuredShopIds', [$featured->id]));
});

test('asking for verification needs a paid verification add-on', function (): void {
    $shop = promotedShop('Grand Arena');
    $owner = User::factory()->create(['tenant_id' => $shop->tenant_id]);
    setPermissionsTeamId($shop->tenant_id);
    $owner->assignRole('Org Superadmin');
    $shop->tenant->users()->attach($owner->id);
    $shop->tenant->update(['slug' => 'grand-arena', 'isolation_mode' => 'shared']);
    $host = eventSubdomainHost('grand-arena');
    $url = "http://{$host}/venue/profile/verify";

    $this->actingAs($owner)->post($url)->assertSessionHas('error');
    expect($shop->fresh()->verification_status)->toBe(Shop::VERIFICATION_UNVERIFIED);

    promote($shop, TenantAddon::TYPE_SHOP_VERIFICATION, now()->addYear());
    $this->actingAs($owner)->post($url)->assertSessionHas('success');
    expect($shop->fresh()->verification_status)->toBe(Shop::VERIFICATION_PENDING);
});

test('a verification ends a year after it was granted', function (): void {
    $stale = Shop::factory()->create(['verification_status' => Shop::VERIFICATION_VERIFIED, 'verified_at' => now()->subMonths(13)]);
    $fresh = Shop::factory()->create(['verification_status' => Shop::VERIFICATION_VERIFIED, 'verified_at' => now()->subMonths(6)]);

    $this->artisan('marketplace:expire-verifications')->assertSuccessful();

    expect($stale->fresh()->verification_status)->toBe(Shop::VERIFICATION_UNVERIFIED)
        ->and($fresh->fresh()->verification_status)->toBe(Shop::VERIFICATION_VERIFIED);
});
