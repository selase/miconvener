<?php

declare(strict_types=1);

use App\Models\Permission;
use App\Models\Shop;
use App\Models\StoreListing;
use App\Models\StoreListingMedia;
use App\Models\StoreMerchandise;
use App\Models\StoreVariant;
use App\Models\User;
use App\Scopes\MerchandiseVisibilityScope;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function (): void {
    refreshTenantDatabases();
    $this->actor = User::factory()->create();
    $this->tenant = setActiveTenantForTest($this->actor);
    Permission::query()->firstOrCreate(['name' => 'manage store catalog', 'guard_name' => 'web'], ['category' => 'store']);
    $this->actor->givePermissionTo('manage store catalog');
    $this->tenant->features()->create(['feature_key' => 'commerce_catalog', 'enabled' => true]);
    $this->actingAs($this->actor);
    $this->url = Str::beforeLast(route('tenant.dashboard', ['subdomain' => $this->tenant->slug]), '/dashboard').'/store/catalog';
    $this->data = ['title' => 'Association shirt', 'slug' => 'association-shirt', 'description' => 'A cotton shirt.',
        'visibility' => 'public', 'currency' => 'GHS', 'collection_instructions' => 'Collect at the office.',
        'variants' => [['sku' => 'SHIRT-M', 'options' => ['Size' => 'M'], 'price_pesewas' => 12000, 'is_active' => true,
            'initial_stock' => 5, 'idempotency_key' => (string) Str::uuid()]]];
});

it('onboards a missing shop only on an authorised write and records opening stock', function (): void {
    $this->get($this->url)->assertOk();
    expect($this->tenant->shop()->exists())->toBeFalse();
    $response = $this->postJson($this->url, $this->data)->assertCreated();
    $listing = StoreListing::withoutGlobalScope(MerchandiseVisibilityScope::class)->findOrFail($response->json('listing.id'));
    expect($listing->listing_kind)->toBe('merchandise')->and($listing->status)->toBe('draft');
    $variant = $listing->variants()->firstOrFail();
    expect($variant->on_hand)->toBe(5)->and($variant->movements()->count())->toBe(1)
        ->and($listing->shop->tenant_id)->toBe($this->tenant->id);
});

it('reuses an existing verified shop without changing its profile', function (): void {
    $shop = Shop::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Existing seller', 'verification_status' => 'verified']);
    $this->postJson($this->url, $this->data)->assertCreated();
    expect($this->tenant->shop()->count())->toBe(1)->and($shop->fresh()->name)->toBe('Existing seller')
        ->and($shop->fresh()->verification_status)->toBe('verified');
});

it('rejects inactive shops without reactivating them', function (): void {
    $shop = Shop::factory()->create(['tenant_id' => $this->tenant->id, 'is_active' => false]);
    $this->postJson($this->url, $this->data)->assertForbidden();
    expect($shop->fresh()->is_active)->toBeFalse();
});

it('requires catalog permission and a current rollout flag', function (string $missing): void {
    if ($missing === 'permission') {
        $this->actor->revokePermissionTo('manage store catalog');
    } else {
        $this->tenant->features()->where('feature_key', 'commerce_catalog')->update(['enabled' => false]);
    }
    $this->postJson($this->url, $this->data)->assertForbidden();
    expect($this->tenant->shop()->exists())->toBeFalse();
})->with(['permission', 'feature']);

it('cannot edit another seller listing or use a venue as merchandise', function (string $kind): void {
    $listing = $kind === 'merchandise' ? StoreMerchandise::factory()->create()->listing_id
        : StoreListing::factory()->create(['shop_id' => Shop::factory()->create(['tenant_id' => $this->tenant->id])->id, 'listing_kind' => 'venue'])->id;
    $this->putJson($this->url.'/'.$listing, $this->data)->assertNotFound();
})->with(['merchandise', 'venue']);

it('rejects malformed minor-unit prices', function (mixed $price): void {
    $this->data['variants'][0]['price_pesewas'] = $price;
    $this->postJson($this->url, $this->data)->assertUnprocessable()->assertJsonValidationErrors('variants.0.price_pesewas');
})->with([-1, '120.50', 'not a number']);

it('rejects duplicate canonical options or seller sku', function (string $duplicate): void {
    $second = $this->data['variants'][0];
    $second['sku'] = $duplicate === 'sku' ? ' shirt-m ' : 'SHIRT-SECOND';
    $second['options'] = $duplicate === 'options' ? [' size ' => ' m '] : ['Size' => 'L'];
    $this->data['variants'][] = $second;
    $this->postJson($this->url, $this->data)->assertUnprocessable();
    expect(StoreVariant::query()->count())->toBe(0);
})->with(['options', 'sku']);

it('requires complete publication and respects platform takedown', function (): void {
    $this->data['collection_instructions'] = '';
    $response = $this->postJson($this->url, $this->data)->assertCreated();
    $id = $response->json('listing.id');
    $this->postJson($this->url.'/'.$id.'/publish')->assertUnprocessable();
    $listing = StoreListing::withoutGlobalScope(MerchandiseVisibilityScope::class)->findOrFail($id);
    $listing->merchandise->update(['collection_instructions' => 'Office collection']);
    $this->postJson($this->url.'/'.$id.'/publish')->assertOk();
    $listing->forceFill(['taken_down_at' => now(), 'status' => 'suspended'])->save();
    $this->postJson($this->url.'/'.$id.'/publish')->assertForbidden();
    expect($listing->fresh()->status)->toBe('suspended');
});

it('edits variants without rewriting stock and exposes audited corrections', function (): void {
    $response = $this->postJson($this->url, $this->data)->assertCreated();
    $id = $response->json('listing.id');
    $variant = StoreVariant::query()->firstOrFail();
    $edited = $this->data;
    $edited['variants'][0] = ['id' => $variant->id, 'sku' => 'SHIRT-M', 'options' => ['Size' => 'M'], 'price_pesewas' => 13000, 'is_active' => true];
    $this->putJson($this->url.'/'.$id, $edited)->assertOk();
    expect($variant->fresh()->on_hand)->toBe(5);
    $key = (string) Str::uuid();
    $path = $this->url.'/'.$id.'/variants/'.$variant->id.'/adjustments';
    $payload = ['delta' => -1, 'reason' => 'Damaged', 'idempotency_key' => $key];
    $this->postJson($path, $payload)->assertOk();
    $this->postJson($path, $payload)->assertOk();
    expect($variant->fresh()->on_hand)->toBe(4)->and($variant->movements()->count())->toBe(2);
    $this->get($this->url.'/'.$id)->assertOk()->assertSee('Damaged');
    $this->postJson($this->url.'/'.$id.'/archive')->assertOk();
    expect(StoreMerchandise::query()->where('listing_id', $id)->firstOrFail()->archived_at)->not->toBeNull();
});

it('rejects arbitrary media url fields', function (): void {
    $this->data['photos'] = ['https://example.test/arbitrary.jpg'];
    $this->postJson($this->url, $this->data)->assertUnprocessable()->assertJsonValidationErrors('photos');
});

it('stores seller photos privately and applies image validation', function (string $type): void {
    Storage::fake('commerce-media-local');
    $id = $this->postJson($this->url, $this->data)->assertCreated()->json('listing.id');
    $file = match ($type) {
        'valid' => UploadedFile::fake()->image('shirt.jpg'),
        'svg' => UploadedFile::fake()->create('photo.svg', 1, 'image/svg+xml'),
        'oversize' => UploadedFile::fake()->image('shirt.jpg')->size(5121),
        'text' => UploadedFile::fake()->create('photo.txt', 1, 'text/plain'),
    };
    $response = $this->post($this->url.'/'.$id.'/photos', ['photo' => $file], ['Accept' => 'application/json']);
    if ($type !== 'valid') {
        $response->assertUnprocessable();
        expect(StoreListingMedia::withoutGlobalScope('merchandise')->count())->toBe(0);
    } else {
        $response->assertCreated();
        $photo = StoreListingMedia::withoutGlobalScope('merchandise')->firstOrFail();
        expect($photo->disk)->toBe('commerce-media-local')->and($photo->byte_size)->toBeGreaterThan(0);
        Storage::disk('commerce-media-local')->assertExists($photo->file_path);
    }
})->with(['valid', 'svg', 'oversize', 'text']);

it('enforces the tenant storage allowance and counts private merchandise bytes', function (): void {
    Storage::fake('commerce-media-local');
    $id = $this->postJson($this->url, $this->data)->assertCreated()->json('listing.id');
    $this->post($this->url.'/'.$id.'/photos', ['photo' => UploadedFile::fake()->image('one.jpg')], ['Accept' => 'application/json'])->assertCreated();
    $bytes = (int) StoreListingMedia::withoutGlobalScope('merchandise')->sum('byte_size');
    expect(app(App\Services\Tenancy\StorageMeter::class)->calculateUsage($this->tenant))->toBe($bytes);
    $this->tenant->features()->create(['feature_key' => 'storage_bytes', 'enabled' => true, 'meta' => ['type' => 'limit', 'value' => $bytes]]);
    $this->post($this->url.'/'.$id.'/photos', ['photo' => UploadedFile::fake()->image('two.jpg')], ['Accept' => 'application/json'])->assertUnprocessable();
    expect(Storage::disk('commerce-media-local')->allFiles())->toHaveCount(1);
});

it('limits photos to eight and cleans up an object when metadata persistence fails', function (): void {
    Storage::fake('commerce-media-local');
    $id = $this->postJson($this->url, $this->data)->assertCreated()->json('listing.id');
    $listing = StoreListing::withoutGlobalScope(MerchandiseVisibilityScope::class)->findOrFail($id);
    $fail = true;
    StoreListingMedia::creating(function () use (&$fail): void {
        if ($fail) {
            throw new RuntimeException('Test metadata failure');
        }
    });
    try {
        app(App\Services\Marketplace\MerchandiseMediaStorage::class)->put($this->tenant, $listing, UploadedFile::fake()->image('failed.jpg'));
        $this->fail('The injected metadata failure must occur.');
    } catch (RuntimeException $error) {
        expect($error->getMessage())->toBe('Test metadata failure');
    } finally {
        $fail = false;
    }
    expect(Storage::disk('commerce-media-local')->allFiles())->toBeEmpty();
    foreach (range(1, 8) as $number) {
        StoreListingMedia::query()->create(['listing_id' => $id, 'media_type' => 'photo', 'file_path' => 'commerce-media/'.$this->tenant->id.'/'.$id.'/'.$number.'.jpg', 'status' => 'approved']);
    }
    $this->post($this->url.'/'.$id.'/photos', ['photo' => UploadedFile::fake()->image('ninth.jpg')], ['Accept' => 'application/json'])->assertUnprocessable();
    expect(StoreListingMedia::withoutGlobalScope('merchandise')->count())->toBe(8);
});

it('removes only an owned product photo and makes its copied URL unusable', function (): void {
    Storage::fake('commerce-media-local');
    $id = $this->postJson($this->url, $this->data)->assertCreated()->json('listing.id');
    $photo = $this->post($this->url.'/'.$id.'/photos', ['photo' => UploadedFile::fake()->image('shirt.jpg')], ['Accept' => 'application/json'])->assertCreated()->json('photo');
    $stored = StoreListingMedia::withoutGlobalScope('merchandise')->findOrFail($photo['id']);
    $other = StoreMerchandise::factory()->create();
    $this->deleteJson($this->url.'/'.$other->listing_id.'/photos/'.$stored->id)->assertNotFound();
    Storage::disk('commerce-media-local')->assertExists($stored->file_path);
    $this->deleteJson($this->url.'/'.$id.'/photos/'.$stored->id)->assertOk();
    Storage::disk('commerce-media-local')->assertMissing($stored->file_path);
    $this->get($photo['url'])->assertNotFound();
});

it('can safely retry photo removal if deleting its metadata fails after storage deletion', function (): void {
    Storage::fake('commerce-media-local');
    $id = $this->postJson($this->url, $this->data)->assertCreated()->json('listing.id');
    $listing = StoreListing::withoutGlobalScope(MerchandiseVisibilityScope::class)->findOrFail($id);
    $storage = app(App\Services\Marketplace\MerchandiseMediaStorage::class);
    $photo = $storage->put($this->tenant, $listing, UploadedFile::fake()->image('shirt.jpg'));
    $fail = true;
    StoreListingMedia::deleting(function () use (&$fail): void {
        if ($fail) {
            $fail = false;
            throw new RuntimeException('Test removal metadata failure');
        }
    });
    try {
        $storage->remove($this->tenant, $listing, $photo->id);
        $this->fail('The simulated metadata failure must occur.');
    } catch (RuntimeException $error) {
        expect($error->getMessage())->toBe('Test removal metadata failure');
    }
    expect(StoreListingMedia::withoutGlobalScope('merchandise')->whereKey($photo->id)->exists())->toBeTrue();
    $storage->remove($this->tenant, $listing, $photo->id);
    expect(StoreListingMedia::withoutGlobalScope('merchandise')->whereKey($photo->id)->exists())->toBeFalse();
    Storage::disk('commerce-media-local')->assertMissing($photo->file_path);
});

it('bounds option names at the same limit as the seller editor', function (): void {
    $this->data['variants'][0]['options'] = [str_repeat('x', 51) => 'M'];
    $this->postJson($this->url, $this->data)->assertUnprocessable()->assertJsonValidationErrors('variants.0.options');
});

it('refuses organisation hard deletion before removing its logo when merchandise audit records exist', function (): void {
    $this->postJson($this->url, $this->data)->assertCreated();
    Storage::fake('public');
    Storage::disk('public')->put('tenant/logo/catalog.png', 'logo');
    $this->tenant->update(['logo' => 'tenant/logo/catalog.png']);
    $response = app(App\Http\Controllers\Admin\TenantController::class)->destroy((string) $this->tenant->uuid);
    expect($response->getStatusCode())->toBe(409);
    expect($response->getData(true)['message'])->toContain('Deactivate');
    expect($this->tenant->fresh())->not->toBeNull();
    Storage::disk('public')->assertExists('tenant/logo/catalog.png');
    expect(App\Models\StoreStockMovement::query()->count())->toBe(1);
});
