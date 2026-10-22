<?php

declare(strict_types=1);

namespace App\Services\Marketplace;

use App\Models\StoreListing;
use App\Models\StoreListingMedia;
use App\Models\StoreVariant;

final class MerchandisePayload
{
    /** Caller must establish current public/member or owner access first.
     * @return array<string,mixed>
     */
    public function forViewer(StoreListing $listing): array
    {
        abort_unless($listing->listing_kind === StoreListing::KIND_MERCHANDISE, 404);
        $listing->loadMissing(['merchandise', 'shop', 'variants', 'media' => fn ($media) => $media->withoutGlobalScope('merchandise')->photos()->approved()]);
        abort_unless($listing->merchandise !== null, 404);

        return ['id' => $listing->id, 'slug' => $listing->slug, 'title' => $listing->title, 'description' => $listing->description,
            'listing_kind' => StoreListing::KIND_MERCHANDISE, 'currency' => $listing->merchandise->currency,
            'visibility' => $listing->merchandise->visibility, 'collection_instructions' => $listing->merchandise->collection_instructions,
            'seller' => ['name' => $listing->shop->name, 'slug' => $listing->shop->slug],
            'variants' => $listing->variants->filter(fn (StoreVariant $variant): bool => $variant->is_active)->sortBy('sku')->values()->map(fn (StoreVariant $variant): array => [
                'id' => $variant->id, 'options' => $variant->options, 'price_pesewas' => $variant->price_pesewas, 'available' => $variant->on_hand > 0,
            ])->all(),
            'photos' => $listing->media->filter(fn (StoreListingMedia $photo): bool => $photo->media_type === StoreListingMedia::TYPE_PHOTO && $photo->status === StoreListingMedia::STATUS_APPROVED)->values()
                ->map(fn (StoreListingMedia $photo): array => ['id' => $photo->id, 'url' => route('marketplace.items.photos', ['slug' => $listing->slug, 'media' => $photo->id]), 'title' => $photo->title])->all(),
            'purchasing_enabled' => false];
    }
}
