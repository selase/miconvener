<?php

declare(strict_types=1);

use App\Models\Member;
use App\Models\StoreListing;
use App\Models\StoreMerchandise;
use App\Models\StoreVariant;
use App\Scopes\MerchandiseVisibilityScope;
use App\Services\Events\PlatformAttendeeVerification;
use Inertia\Testing\AssertableInertia as Assert;

function storefrontMerchandise(string $visibility = 'public'): StoreListing
{
    $details = StoreMerchandise::factory()->create(['visibility' => $visibility]);
    StoreVariant::factory()->create(['listing_id' => $details->listing_id, 'price_pesewas' => 1450, 'options' => ['Size' => 'M']]);
    $details->shop->tenant->features()->create(['feature_key' => 'commerce_catalog', 'enabled' => true]);
    $listing = StoreListing::withoutGlobalScope(MerchandiseVisibilityScope::class)->findOrFail($details->listing_id);
    $listing->update(['status' => 'published']);

    return $listing;
}

it('shows a catalog item with sale variants and no checkout action', function (): void {
    $listing = storefrontMerchandise();
    $this->get('/marketplace/items/'.$listing->slug)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Public/Marketplace/Items/Show')->where('item.title', $listing->title)
        ->where('item.variants.0.price_pesewas', 1450)->where('item.variants.0.options.Size', 'M')
        ->where('item.variants.0.available', false)->where('item.purchasing_enabled', false)
        ->missing('item.rental_price_pesewas')->missing('item.shop.tax_id'));
});

it('keeps venue links and only authorised merchandise in a shared storefront', function (): void {
    $listing = storefrontMerchandise();
    $venue = StoreListing::factory()->published()->create(['shop_id' => $listing->shop_id, 'listing_kind' => 'venue']);
    $private = StoreListing::factory()->published()->create(['shop_id' => $listing->shop_id, 'listing_kind' => 'merchandise', 'title' => 'Private member notebook']);
    StoreMerchandise::factory()->create(['listing_id' => $private->id, 'shop_id' => $listing->shop_id, 'visibility' => 'members']);
    StoreVariant::factory()->create(['listing_id' => $private->id]);
    $listing->shop->update(['tax_id' => 'PRIVATE-TAX-EVIDENCE', 'verification_documents' => ['private-proof.pdf']]);
    $this->get('/marketplace/'.$listing->shop->slug)->assertOk()->assertDontSee('Private member notebook')->assertDontSee('PRIVATE-TAX-EVIDENCE')
        ->assertDontSee('private-proof.pdf')->assertInertia(fn (Assert $page) => $page
        ->component('Public/Marketplace/Storefront/Show')->has('spaces', 1)->where('spaces.0.slug', $venue->slug)
        ->has('merchandise', 1)->where('merchandise.0.slug', $listing->slug)->missing('shop.tax_id')->missing('shop.tenant'));
});

it('uses the native verified email and current membership for member item details', function (): void {
    $listing = storefrontMerchandise('members');
    $url = '/marketplace/items/'.$listing->slug;
    $this->get($url)->assertNotFound();
    $member = Member::factory()->create(['tenant_id' => $listing->shop->tenant_id]);
    $this->withSession([PlatformAttendeeVerification::SESSION_KEY => ['email' => $member->email, 'expires_at' => now()->addHour()->timestamp]])
        ->get($url)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    $member->update(['status' => 'exited']);
    $this->get($url)->assertNotFound();
});

it('hides an open item after the catalog flag is revoked', function (): void {
    $listing = storefrontMerchandise();
    $this->get('/marketplace/items/'.$listing->slug)->assertOk();
    $listing->shop->tenant->features()->where('feature_key', 'commerce_catalog')->update(['enabled' => false]);
    $this->get('/marketplace/items/'.$listing->slug)->assertNotFound();
});

it('lets an authorised seller open a creation form without creating a shop', function (): void {
    $actor = App\Models\User::factory()->create();
    $tenant = setActiveTenantForTest($actor);
    App\Models\Permission::query()->firstOrCreate(['name' => 'manage store catalog', 'guard_name' => 'web'], ['category' => 'store']);
    $actor->givePermissionTo('manage store catalog');
    $tenant->features()->create(['feature_key' => 'commerce_catalog', 'enabled' => true]);
    $this->actingAs($actor)->get(route('tenant.store.catalog.create', ['subdomain' => $tenant->slug]))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Tenant/Store/Catalog/Form')->where('item', null));
    expect($tenant->shop()->exists())->toBeFalse();
});

it('allows a seller to hide and restore a catalog item without losing its stock history', function (): void {
    $listing = storefrontMerchandise();
    $actor = App\Models\User::factory()->create();
    $tenant = $listing->shop->tenant;
    $actor->tenants()->attach($tenant->id);
    setPermissionsTeamId($tenant->id);
    App\Models\Permission::query()->firstOrCreate(['name' => 'manage store catalog', 'guard_name' => 'web'], ['category' => 'store']);
    $actor->givePermissionTo('manage store catalog');
    $this->actingAs($actor);
    $variant = $listing->variants()->firstOrFail();
    app(App\Services\Marketplace\MerchandiseInventory::class)->adjust($variant, 2, 'Receipt', (string) Illuminate\Support\Str::uuid(), $actor);
    $draft = route('tenant.store.catalog.draft', ['subdomain' => $tenant->slug, 'listing' => $listing->id]);
    $archive = route('tenant.store.catalog.archive', ['subdomain' => $tenant->slug, 'listing' => $listing->id]);
    $publish = route('tenant.store.catalog.publish', ['subdomain' => $tenant->slug, 'listing' => $listing->id]);
    $this->postJson($draft)->assertOk()->assertJsonPath('listing.status', 'draft');
    expect(StoreListing::query()->find($listing->id))->toBeNull();
    $this->postJson($publish)->assertOk();
    $this->postJson($archive)->assertOk()->assertJsonPath('listing.archived', true);
    $this->postJson($draft)->assertOk()->assertJsonPath('listing.archived', false)->assertJsonPath('listing.status', 'draft');
    expect($listing->variants()->count())->toBe(1)->and($variant->fresh()->on_hand)->toBe(2)->and($variant->movements()->count())->toBe(1);
    $listing->forceFill(['taken_down_at' => now(), 'status' => 'suspended'])->save();
    $this->postJson($draft)->assertOk()->assertJsonPath('listing.status', 'suspended');
    $this->postJson($publish)->assertForbidden();
});

it('serves fresh no-store JSON for focus revalidation without private fields', function (): void {
    $listing = storefrontMerchandise();
    $this->getJson('/marketplace/items/'.$listing->slug)->assertOk()->assertJsonPath('item.title', $listing->title)
        ->assertHeader('Cache-Control', 'no-store, private');
    $this->getJson('/marketplace/'.$listing->shop->slug)->assertOk()->assertJsonCount(1, 'merchandise')->assertJsonMissingPath('shop.tax_id');
});

it('paginates visible merchandise instead of silently truncating the catalog', function (): void {
    $first = storefrontMerchandise();
    foreach (range(1, 24) as $number) {
        $listing = StoreListing::factory()->published()->create(['shop_id' => $first->shop_id, 'listing_kind' => 'merchandise']);
        StoreMerchandise::factory()->create(['listing_id' => $listing->id, 'shop_id' => $first->shop_id]);
        StoreVariant::factory()->create(['listing_id' => $listing->id]);
    }
    $base = '/marketplace/'.$first->shop->slug;
    $this->getJson($base)->assertOk()->assertJsonCount(24, 'merchandise')->assertJsonStructure(['merchandiseLinks']);
    $this->getJson($base.'?items_page=2')->assertOk()->assertJsonCount(1, 'merchandise');
});

it('links the organisation page to its enabled store without requiring dues', function (): void {
    $listing = storefrontMerchandise();
    $tenant = $listing->shop->tenant;
    $tenant->features()->create(['feature_key' => 'community', 'enabled' => true]);
    $url = route('public.community.about', ['subdomain' => $tenant->slug]);
    $this->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page->where('storeUrl', route('marketplace.storefront.show', ['merchant_slug' => $listing->shop->slug])));
    $tenant->features()->where('feature_key', 'commerce_catalog')->update(['enabled' => false]);
    $this->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page->where('storeUrl', null));
});

it('shows made-to-order public enquiries without exposing a purchase action or requiring member verification', function (): void {
    $listing = storefrontMerchandise();
    $listing->merchandise->update(['order_mode' => 'made_to_order', 'enquiry_phone' => '+233208333151']);
    $this->getJson('/marketplace/items/'.$listing->slug)->assertOk()
        ->assertJsonPath('item.order_mode', 'made_to_order')
        ->assertJsonPath('item.enquiry_phone', '+233208333151')
        ->assertJsonPath('item.purchasing_enabled', false)
        ->assertJsonPath('item.variants.0.price_pesewas', 1450);
});

it('keeps enquiry contacts private on members-only items and omits them for stocked items', function (): void {
    $listing = storefrontMerchandise('members');
    $listing->merchandise->update(['order_mode' => 'made_to_order', 'enquiry_phone' => '+233208333151']);
    $this->getJson('/marketplace/items/'.$listing->slug)->assertNotFound()->assertDontSee('+233208333151');
    $listing->merchandise->update(['visibility' => 'public', 'order_mode' => 'stock']);
    $this->getJson('/marketplace/items/'.$listing->slug)->assertOk()->assertJsonPath('item.order_mode', 'stock')->assertJsonPath('item.enquiry_phone', null);
});
