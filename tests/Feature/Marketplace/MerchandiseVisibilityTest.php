<?php

declare(strict_types=1);

use App\Enum\MemberStatusEnum;
use App\Models\Member;
use App\Models\StoreListing;
use App\Models\StoreMerchandise;
use App\Models\StoreVariant;
use App\Scopes\TenantScope;
use App\Services\Events\PlatformAttendeeVerification;
use App\Services\Marketplace\MarketplaceSearchService;
use App\Services\Marketplace\MerchandiseAccess;
use App\Services\Marketplace\VenueBookingService;

function visibleMerchandiseFixture(): StoreListing
{
    $details = StoreMerchandise::factory()->create();
    StoreVariant::factory()->create(['listing_id' => $details->listing_id]);
    $details->shop->tenant->features()->create(['feature_key' => 'commerce_catalog', 'enabled' => true]);
    $listing = StoreListing::withoutGlobalScopes()->findOrFail($details->listing_id);
    $listing->update(['status' => StoreListing::STATUS_PUBLISHED]);

    return $listing;
}

it('excludes unsafe merchandise from generic reads and shop counts', function (string $state): void {
    $listing = visibleMerchandiseFixture();
    $details = StoreMerchandise::query()->where('listing_id', $listing->id)->firstOrFail();
    match ($state) {
        'members' => $details->update(['visibility' => 'members']),
        'no_collection' => $details->update(['collection_instructions' => '   ']),
        'archived' => $details->update(['archived_at' => now()]),
        'draft', 'suspended' => $listing->update(['status' => $state]),
        'taken_down' => $listing->forceFill(['taken_down_at' => now()])->save(),
        'inactive_shop' => $listing->shop->update(['is_active' => false]),
        'inactive_tenant' => $listing->shop->tenant->update(['status' => 'deactivated']),
        'catalog_off' => $listing->shop->tenant->features()->where('feature_key', 'commerce_catalog')->update(['enabled' => false]),
        'no_variant' => StoreVariant::query()->where('listing_id', $listing->id)->delete(),
        'no_active_variant' => StoreVariant::query()->where('listing_id', $listing->id)->update(['is_active' => false]),
    };
    expect(StoreListing::query()->find($listing->id))->toBeNull()
        ->and($listing->shop->listings()->whereKey($listing->id)->count())->toBe(0);
})->with(['members', 'no_collection', 'archived', 'draft', 'suspended', 'taken_down', 'inactive_shop', 'inactive_tenant', 'catalog_off', 'no_variant', 'no_active_variant']);

it('allows complete enabled public merchandise but keeps it out of legacy discovery', function (array $filters): void {
    $listing = visibleMerchandiseFixture();
    expect(StoreListing::query()->find($listing->id))->not->toBeNull();
    expect(app(MarketplaceSearchService::class)->searchVenues($filters)->pluck('id')->all())->not->toContain($listing->id);
})->with([[['listing_kind' => 'all']], [['category' => 'other']], [['listing_kind' => 'merchandise']]]);

it('requires a current matching active email membership for private merchandise', function (): void {
    $listing = visibleMerchandiseFixture();
    StoreMerchandise::query()->where('listing_id', $listing->id)->update(['visibility' => 'members']);
    $member = Member::factory()->create(['tenant_id' => $listing->shop->tenant_id, 'email' => 'member@example.test']);
    $session = app('session.store');
    $access = app(MerchandiseAccess::class);
    expect($access->canRead($listing, $session))->toBeFalse();
    $session->put(PlatformAttendeeVerification::SESSION_KEY, ['email' => $member->email, 'expires_at' => now()->addHour()->timestamp]);
    expect($access->canRead($listing, $session))->toBeTrue();
    $member->update(['status' => MemberStatusEnum::Lapsed]);
    expect($access->canRead($listing, $session))->toBeFalse();
    $member->update(['status' => MemberStatusEnum::Active, 'email' => 'changed@example.test']);
    expect($access->canRead($listing, $session))->toBeFalse();
    $member->update(['email' => 'member@example.test']);
    $session->put(PlatformAttendeeVerification::SESSION_KEY, ['email' => $member->email, 'expires_at' => now()->subSecond()->timestamp]);
    expect($access->canRead($listing, $session))->toBeFalse();
});

it('rejects wrong tenant membership and rechecks publication between reads', function (): void {
    $listing = visibleMerchandiseFixture();
    StoreMerchandise::query()->where('listing_id', $listing->id)->update(['visibility' => 'members']);
    $member = Member::factory()->create(['email' => 'elsewhere@example.test']);
    $session = app('session.store');
    $session->put(PlatformAttendeeVerification::SESSION_KEY, ['email' => $member->email, 'expires_at' => now()->addHour()->timestamp]);
    $access = app(MerchandiseAccess::class);
    expect($access->canRead($listing, $session))->toBeFalse();
    Member::withoutGlobalScope(TenantScope::class)->whereKey($member->id)->update(['tenant_id' => $listing->shop->tenant_id]);
    expect($access->canRead($listing, $session))->toBeTrue();
    $listing->update(['status' => 'draft']);
    expect($access->canRead($listing, $session))->toBeFalse();
});

it('never creates a legacy venue booking for merchandise', function (): void {
    $listing = visibleMerchandiseFixture();
    app(VenueBookingService::class)->createBooking($listing, []);
})->throws(Illuminate\Validation\ValidationException::class);

it('rejects merchandise through every public legacy venue action', function (string $method, string $path): void {
    $listing = visibleMerchandiseFixture();
    $start = now()->addDays(3)->setTime(9, 0);
    $payload = ['starts_at' => $start->toIso8601String(), 'ends_at' => $start->copy()->addHours(2)->toIso8601String(),
        'planner_name' => 'Test member', 'planner_email' => 'test@example.test', 'event_type' => 'Meeting',
        'guest_count' => 5, 'layout_style' => 'banquet', 'agreed_to_terms' => '1', 'requirements_description' => 'Test inquiry'];
    $this->{$method}(str_replace('{slug}', $listing->slug, $path), $payload)->assertNotFound();
})->with([
    ['get', '/marketplace/venues/{slug}'],
    ['get', '/marketplace/venues/{slug}/booked-slots'],
    ['post', '/marketplace/venues/{slug}/check-availability'],
    ['post', '/marketplace/venues/{slug}/book'],
    ['post', '/marketplace/listings/{slug}/rfq'],
]);

it('rejects merchandise in legacy supplier quote creation', function (): void {
    app(App\Services\Marketplace\MarketplaceVendorService::class)->createRfq(visibleMerchandiseFixture(), []);
})->throws(Illuminate\Validation\ValidationException::class);

it('keeps merchandise out of legacy tenant venue editing even when public', function (): void {
    $listing = visibleMerchandiseFixture();
    $tenant = $listing->shop->tenant;
    $actor = App\Models\User::factory()->create();
    $actor->tenants()->attach($tenant->id);
    App\Models\Permission::query()->firstOrCreate(['name' => 'manage venue', 'guard_name' => 'web'], ['category' => 'venue']);
    setPermissionsTeamId($tenant->id);
    $actor->givePermissionTo('manage venue');
    app(App\Services\Tenancy\TenantContext::class)->setTenant($tenant);
    $this->actingAs($actor);
    $controller = app(App\Http\Controllers\Tenant\Venue\VenueListingController::class);
    $controller->edit(Illuminate\Http\Request::create('/'), $tenant->slug, $listing);
})->throws(Symfony\Component\HttpKernel\Exception\HttpException::class);

it('never reveals merchandise images as a public storage or remote URL', function (): void {
    $listing = visibleMerchandiseFixture();
    $media = App\Models\StoreListingMedia::query()->create(['listing_id' => $listing->id, 'media_type' => 'photo', 'file_path' => 'https://example.test/private-photo.png', 'status' => 'approved']);
    expect($media->url)->toBe(route('marketplace.items.photos', ['slug' => $listing->slug, 'media' => $media->id]));
    expect($media->toArray())->not->toHaveKey('file_path');
});

it('rechecks protected photo access after publication or member visibility changes', function (): void {
    Illuminate\Support\Facades\Storage::fake('commerce-media-local');
    $listing = visibleMerchandiseFixture();
    $photo = app(App\Services\Marketplace\MerchandiseMediaStorage::class)->put($listing->shop->tenant, $listing, Illuminate\Http\UploadedFile::fake()->image('shirt.jpg'));
    $url = route('marketplace.items.photos', ['slug' => $listing->slug, 'media' => $photo->id]);
    $this->get($url)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('Cache-Control', 'no-store, private');
    StoreMerchandise::query()->where('listing_id', $listing->id)->update(['visibility' => 'members']);
    $this->get($url)->assertNotFound();
    expect(App\Models\StoreListingMedia::query()->whereKey($photo->id)->exists())->toBeFalse();
    $member = Member::factory()->create(['tenant_id' => $listing->shop->tenant_id]);
    $this->withSession([PlatformAttendeeVerification::SESSION_KEY => ['email' => $member->email, 'expires_at' => now()->addHour()->timestamp]])->get($url)->assertOk();
    $listing->update(['status' => 'draft']);
    $this->get($url)->assertNotFound();
});

it('does not expose incomplete or private merchandise on the storefront or MCP', function (): void {
    $listing = visibleMerchandiseFixture();
    StoreMerchandise::query()->where('listing_id', $listing->id)->update(['visibility' => 'members']);
    $this->get('/marketplace/'.$listing->shop->slug)->assertOk()->assertDontSee($listing->title);
    $this->postJson('/mcp/marketplace', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
        'params' => ['name' => 'get_venue_details', 'arguments' => ['slug' => $listing->slug]]])->assertDontSee($listing->title);
    $incomplete = StoreListing::factory()->published()->create(['shop_id' => $listing->shop_id, 'listing_kind' => 'merchandise']);
    expect(StoreListing::query()->find($incomplete->id))->toBeNull();
});

it('never treats a phone proof or an exited member as purchase eligibility', function (): void {
    $listing = visibleMerchandiseFixture();
    StoreMerchandise::query()->where('listing_id', $listing->id)->update(['visibility' => 'members']);
    $member = Member::factory()->create(['tenant_id' => $listing->shop->tenant_id, 'status' => MemberStatusEnum::Exited]);
    $session = app('session.store');
    $session->put(App\Services\Community\PhoneVerification::SESSION_KEY, ['phone' => $member->phone, 'expires_at' => now()->addHour()->timestamp]);
    $access = app(MerchandiseAccess::class);
    expect($access->canRead($listing, $session))->toBeFalse();
    $session->put(PlatformAttendeeVerification::SESSION_KEY, ['email' => $member->email, 'expires_at' => now()->addHour()->timestamp]);
    expect($access->canRead($listing, $session))->toBeFalse();
});

it('serialises public item fields without exposing seller or storage internals', function (): void {
    $listing = visibleMerchandiseFixture();
    $payload = app(App\Services\Marketplace\MerchandisePayload::class)->forViewer($listing);
    expect($payload['id'])->toBe($listing->id)
        ->and($payload['seller'])->toBe(['name' => $listing->shop->name, 'slug' => $listing->shop->slug])
        ->and($payload['variants'][0])->toHaveKeys(['id', 'options', 'price_pesewas', 'available'])
        ->and($payload)->not->toHaveKeys(['shop', 'tenant', 'embedding', 'rental_price_pesewas']);
});

it('refuses supplier checkout even for a pre-existing quote referencing merchandise', function (): void {
    $listing = visibleMerchandiseFixture();
    $quote = App\Models\MarketplaceQuote::query()->create(['quote_reference' => 'RFQ-MERCH-TEST', 'tenant_id' => $listing->shop->tenant_id,
        'shop_id' => $listing->shop_id, 'store_listing_id' => $listing->id, 'planner_name' => 'Test', 'planner_email' => 'test@example.test',
        'requirements_description' => 'Invalid merchandise quote', 'status' => 'quoted']);
    app(App\Services\Marketplace\MarketplaceVendorService::class)->initializeCheckout($quote, 'https://example.test/callback');
})->throws(RuntimeException::class, 'Merchandise cannot use supplier quote checkout.');

it('rejects merchandise as an event location without creating an event', function (): void {
    $listing = visibleMerchandiseFixture();
    $tenant = $listing->shop->tenant;
    $actor = App\Models\User::factory()->create();
    $actor->tenants()->attach($tenant->id);
    App\Models\Permission::query()->firstOrCreate(['name' => 'create event', 'guard_name' => 'web'], ['category' => 'event']);
    setPermissionsTeamId($tenant->id);
    $actor->givePermissionTo('create event');
    app(App\Services\Tenancy\TenantContext::class)->setTenant($tenant);
    $this->actingAs($actor);
    $request = Illuminate\Http\Request::create('/', 'POST', ['name' => 'A real event', 'status' => 'draft',
        'starts_at' => now()->addDays(3)->toDateTimeString(), 'ends_at' => now()->addDays(3)->addHour()->toDateTimeString(),
        'timezone' => 'Africa/Accra', 'location_type' => 'in_person', 'ticket_price' => 0, 'currency' => 'GHS', 'store_listing_id' => $listing->id]);
    try {
        app(App\Http\Controllers\Tenant\EventController::class)->store($request, $tenant->slug);
        $this->fail('An event cannot use merchandise as a venue.');
    } catch (Illuminate\Validation\ValidationException $error) {
        expect($error->errors())->toHaveKey('store_listing_id');
        expect(App\Models\Event::query()->where('tenant_id', $tenant->id)->count())->toBe(0);
    }
});

it('does not reserve merchandise through the internal room service', function (): void {
    $listing = visibleMerchandiseFixture();
    $tenant = $listing->shop->tenant;
    $member = Member::factory()->create(['tenant_id' => $tenant->id, 'is_staff' => true]);
    try {
        app(App\Services\Venue\InternalBookings::class)->request($tenant, $listing, $member, now()->addDay(), now()->addDay()->addHour(), 'Test');
        $this->fail('Merchandise cannot be reserved as an internal room.');
    } catch (Illuminate\Validation\ValidationException $error) {
        expect($error->errors()['room'])->toBe(['Merchandise cannot be reserved as a room.']);
        expect(App\Models\VenueBooking::query()->where('store_listing_id', $listing->id)->count())->toBe(0);
    }
});
