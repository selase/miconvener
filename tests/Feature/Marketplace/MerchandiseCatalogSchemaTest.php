<?php

declare(strict_types=1);

use App\Models\Shop;
use App\Models\StoreListing;
use App\Models\StoreMerchandise;
use App\Models\StoreVariant;
use Illuminate\Database\QueryException;

it('keeps merchandise in the existing shop listing catalog', function (): void {
    $details = StoreMerchandise::factory()->create();
    $variant = StoreVariant::factory()->create(['listing_id' => $details->listing_id]);

    expect(StoreListing::withoutGlobalScope(App\Scopes\MerchandiseVisibilityScope::class)->findOrFail($details->listing_id)->listing_kind)->toBe(StoreListing::KIND_MERCHANDISE)
        ->and($variant->shop_id)->toBe($details->shop_id)
        ->and($variant->price_pesewas)->toBeInt()
        ->and($variant->on_hand)->toBe(0);
});

it('cannot attach merchandise details to a venue', function (): void {
    $venue = StoreListing::factory()->create(['listing_kind' => StoreListing::KIND_VENUE]);
    StoreMerchandise::factory()->create(['listing_id' => $venue->id, 'shop_id' => $venue->shop_id]);
})->throws(QueryException::class);

it('allows only one merchandise detail per listing', function (): void {
    $details = StoreMerchandise::factory()->create();
    StoreMerchandise::factory()->create(['listing_id' => $details->listing_id, 'shop_id' => $details->shop_id]);
})->throws(QueryException::class);

it('enforces variant ownership at the database boundary', function (): void {
    $details = StoreMerchandise::factory()->create();
    StoreVariant::factory()->create(['listing_id' => $details->listing_id, 'shop_id' => Shop::factory()->create()->id]);
})->throws(QueryException::class);

it('normalises sku and option labels before uniqueness checks', function (): void {
    $variant = StoreVariant::factory()->create(['sku' => ' shirt-m ', 'options' => [' Size ' => ' M ', 'Colour' => 'Navy  blue']]);
    expect($variant->sku)->toBe('SHIRT-M');
    expect($variant->option_key)->toBe(hash('sha256', json_encode(['colour' => 'navy blue', 'size' => 'm'], JSON_THROW_ON_ERROR)));
});

it('rejects duplicate option combinations for the same listing', function (): void {
    $variant = StoreVariant::factory()->create(['options' => ['Size' => 'M', 'Colour' => 'Navy']]);
    StoreVariant::factory()->create(['listing_id' => $variant->listing_id, 'options' => ['colour' => ' navy ', 'size' => 'm']]);
})->throws(QueryException::class);

it('enforces nonnegative prices and stock at the database boundary', function (string $field): void {
    StoreVariant::factory()->create([$field => -1]);
})->with(['price_pesewas', 'on_hand'])->throws(QueryException::class);

it('archives merchandise without deleting its variants', function (): void {
    $details = StoreMerchandise::factory()->create();
    $variant = StoreVariant::factory()->create(['listing_id' => $details->listing_id]);
    $details->update(['archived_at' => now()]);
    expect(StoreVariant::query()->find($variant->id))->not->toBeNull();
});

it('keeps sku uniqueness within the seller across different listings', function (): void {
    $first = StoreVariant::factory()->create(['sku' => 'SAME-SKU']);
    $listing = StoreListing::factory()->create(['listing_kind' => StoreListing::KIND_MERCHANDISE, 'shop_id' => $first->shop_id]);
    StoreMerchandise::factory()->create(['listing_id' => $listing->id, 'shop_id' => $first->shop_id]);
    StoreVariant::factory()->create(['listing_id' => $listing->id, 'sku' => ' same-sku ']);
})->throws(QueryException::class);

it('rejects duplicate normalised option names', function (): void {
    StoreVariant::factory()->create(['options' => ['Size' => 'M', ' size ' => 'L']]);
})->throws(Illuminate\Validation\ValidationException::class);
