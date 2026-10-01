<?php

declare(strict_types=1);

namespace App\Services\Marketplace;

use App\Models\Shop;
use App\Models\StoreListing;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

final class MarketplaceSearchService
{
    /**
     * Search and filter venue listings for the public marketplace.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, StoreListing>
     */
    public function searchVenues(array $filters = [], int $perPage = 12): LengthAwarePaginator
    {
        /** @var Builder<StoreListing> $query */
        $query = StoreListing::query()
            ->with(['shop', 'primaryMedia', 'amenities.amenity'])
            ->where('listing_kind', StoreListing::KIND_VENUE)
            ->where('status', StoreListing::STATUS_PUBLISHED)
            ->whereHas('shop', function (Builder $q): void {
                $q->where('is_active', true);
            });

        // 1. Text keyword search (title, description, city, region, or shop name)
        if (! empty($filters['q'])) {
            $keyword = mb_trim((string) $filters['q']);
            $query->where(function (Builder $q) use ($keyword): void {
                $q->where('title', 'ilike', "%{$keyword}%")
                    ->orWhere('description', 'ilike', "%{$keyword}%")
                    ->orWhereHas('shop', function (Builder $sq) use ($keyword): void {
                        $sq->where('name', 'ilike', "%{$keyword}%")
                            ->orWhere('city', 'ilike', "%{$keyword}%")
                            ->orWhere('region', 'ilike', "%{$keyword}%");
                    });
            });
        }

        // 2. City filter
        if (! empty($filters['city'])) {
            $city = mb_trim((string) $filters['city']);
            $query->whereHas('shop', function (Builder $q) use ($city): void {
                $q->where('city', 'ilike', $city);
            });
        }

        // 3. Region filter
        if (! empty($filters['region'])) {
            $region = mb_trim((string) $filters['region']);
            $query->whereHas('shop', function (Builder $q) use ($region): void {
                $q->where('region', 'ilike', $region);
            });
        }

        // 4. Proximity / Geo-Distance filter (lat, lng, radius)
        if (isset($filters['lat'], $filters['lng']) && is_numeric($filters['lat']) && is_numeric($filters['lng'])) {
            $lat = (float) $filters['lat'];
            $lng = (float) $filters['lng'];
            $radiusKm = isset($filters['radius']) && is_numeric($filters['radius'])
                ? (float) $filters['radius']
                : 25.0;

            $query->nearLocation($lat, $lng, $radiusKm);
        }

        // 5. Minimum Capacity filter (by layout style)
        if (! empty($filters['min_capacity']) && is_numeric($filters['min_capacity'])) {
            $minCap = (int) $filters['min_capacity'];
            $style = ! empty($filters['capacity_style']) ? (string) $filters['capacity_style'] : 'banquet';
            $query->minCapacity($minCap, $style);
        }

        // 6. Pricing filters (GHS converted to pesewas)
        if (! empty($filters['min_price']) && is_numeric($filters['min_price'])) {
            $minPesewas = (int) round(((float) $filters['min_price']) * 100);
            $query->where('rental_price_pesewas', '>=', $minPesewas);
        }

        if (! empty($filters['max_price']) && is_numeric($filters['max_price'])) {
            $maxPesewas = (int) round(((float) $filters['max_price']) * 100);
            $query->where('rental_price_pesewas', '<=', $maxPesewas);
        }

        // 7. Price Visibility filter (public, on_request)
        if (! empty($filters['price_visibility'])) {
            $query->where('price_visibility', (string) $filters['price_visibility']);
        }

        // 8. Required Amenities filter (must be marked as included)
        if (! empty($filters['amenities']) && is_array($filters['amenities'])) {
            foreach ($filters['amenities'] as $amenitySlug) {
                if (is_string($amenitySlug) && $amenitySlug !== '') {
                    $query->whereHas('amenities', function (Builder $q) use ($amenitySlug): void {
                        $q->where('is_included', true)
                            ->whereHas('amenity', function (Builder $aq) use ($amenitySlug): void {
                                $aq->where('slug', $amenitySlug);
                            });
                    });
                }
            }
        }

        // 9. Sorting & Verified Host priority
        $sort = $filters['sort'] ?? 'recommended';

        match ($sort) {
            'price_asc' => $query->orderBy('rental_price_pesewas', 'asc'),
            'price_desc' => $query->orderBy('rental_price_pesewas', 'desc'),
            'capacity_desc' => $query->orderByRaw("COALESCE((capacity_breakdown->>'banquet')::int, (capacity_breakdown->>'theater')::int, 0) DESC"),
            'newest' => $query->orderByDesc('created_at'),
            default => $query
                ->leftJoin('shops', 'store_listings.shop_id', '=', 'shops.id')
                ->orderByRaw("CASE WHEN shops.verification_status = 'verified' THEN 0 ELSE 1 END")
                ->select('store_listings.*')
                ->orderBy('store_listings.sort_order')
                ->orderByDesc('store_listings.created_at'),
        };

        return $query->paginate($perPage)->withQueryString();
    }
}
