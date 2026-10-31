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
        $listing->loadMissing(['merchandise', 'shop', 'variants' => fn ($query) => $query->withSum(['allocations as held_quantity' => fn ($allocation) => $allocation->where('state', 'active')->where('expires_at', '>', now())], 'quantity'), 'media' => fn ($media) => $media->withoutGlobalScope('merchandise')->photos()->approved()]);
        abort_unless($listing->merchandise !== null, 404);

        return ['id' => $listing->id, 'slug' => $listing->slug, 'title' => $listing->title, 'description' => $listing->description,
            'order_mode' => $listing->merchandise->order_mode,
            'enquiry_phone' => $listing->merchandise->order_mode === 'made_to_order' ? $listing->merchandise->enquiry_phone : null,
            'listing_kind' => StoreListing::KIND_MERCHANDISE, 'currency' => $listing->merchandise->currency,
            'visibility' => $listing->merchandise->visibility, 'collection_instructions' => $listing->merchandise->collection_instructions,
            'seller' => ['name' => $listing->shop->name, 'slug' => $listing->shop->slug],
            'variants' => $listing->variants->filter(fn (StoreVariant $variant): bool => $variant->is_active)->sortBy('sku')->values()->map(fn (StoreVariant $variant): array => [
                'id' => $variant->id, 'options' => $variant->options, 'price_pesewas' => $variant->price_pesewas, 'available' => $variant->on_hand > (int) $variant->getAttribute('held_quantity'),
            ])->all(),
            'photos' => $listing->media->filter(fn (StoreListingMedia $photo): bool => $photo->media_type === StoreListingMedia::TYPE_PHOTO && $photo->status === StoreListingMedia::STATUS_APPROVED)->values()
                ->map(fn (StoreListingMedia $photo): array => ['id' => $photo->id, 'url' => route('marketplace.items.photos', ['slug' => $listing->slug, 'media' => $photo->id]), 'title' => $photo->title])->all(),
            'purchasing_enabled' => $listing->merchandise->order_mode === 'stock' && $this->purchasingEnabled($listing->shop->tenant)];
    }

    private function purchasingEnabled(\App\Models\Tenant $tenant): bool
    {
        if (! app(CommerceCatalogRollout::class)->enabled($tenant) || ! $tenant->features()->where('feature_key', 'commerce_checkout')->where('enabled', true)->exists()) {
            return false;
        }
        try {
            app(StoreCommercialTerms::class)->resolve($tenant);

            return true;
        } catch (\Illuminate\Validation\ValidationException) {
            return false;
        }
    }
}
