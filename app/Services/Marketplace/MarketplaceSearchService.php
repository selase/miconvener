<?php

declare(strict_types=1);

namespace App\Services\Marketplace;

use App\Models\StoreListing;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

final class MarketplaceSearchService
{
    public function __construct(
        private readonly VenueEmbeddingService $embeddingService = new VenueEmbeddingService,
    ) {}

    /**
     * Search and filter venue listings for the public marketplace.
     * Supports hybrid keyword, geo-distance, structured filters, and vector AI semantic similarity.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, StoreListing>
     */
    public function searchVenues(array $filters = [], int $perPage = 12): LengthAwarePaginator
    {
        /** @var Builder<StoreListing> $query */
        $query = StoreListing::query()
            ->with(['shop', 'primaryMedia', 'amenities.amenity'])
            ->where('store_listings.listing_kind', StoreListing::KIND_VENUE)
            ->where('store_listings.status', StoreListing::STATUS_PUBLISHED)
            ->whereHas('shop', function (Builder $q): void {
                $q->where('is_active', true);
            });

        // 1. Vector AI Semantic Search Prompt
        $aiPrompt = ! empty($filters['ai_prompt']) ? mb_substr(mb_trim((string) $filters['ai_prompt']), 0, 1000) : null;
        $vector = null;

        if ($aiPrompt !== null && $aiPrompt !== '') {
            $vector = $this->embeddingService->generateEmbedding($aiPrompt);
            $query->withSimilarity($vector);
        }

        // 2. Text keyword search (title, description, city, region, or shop name)
        if (! empty($filters['q'])) {
            $keyword = mb_trim((string) $filters['q']);
            $query->where(function (Builder $q) use ($keyword): void {
                $q->where('store_listings.title', 'ilike', "%{$keyword}%")
                    ->orWhere('store_listings.description', 'ilike', "%{$keyword}%")
                    ->orWhereHas('shop', function (Builder $sq) use ($keyword): void {
                        $sq->where('name', 'ilike', "%{$keyword}%")
                            ->orWhere('city', 'ilike', "%{$keyword}%")
                            ->orWhere('region', 'ilike', "%{$keyword}%");
                    });
            });
        }

        // 3. City filter
        if (! empty($filters['city'])) {
            $city = mb_trim((string) $filters['city']);
            $query->whereHas('shop', function (Builder $q) use ($city): void {
                $q->where('city', 'ilike', $city);
            });
        }

        // 4. Region filter
        if (! empty($filters['region'])) {
            $region = mb_trim((string) $filters['region']);
            $query->whereHas('shop', function (Builder $q) use ($region): void {
                $q->where('region', 'ilike', $region);
            });
        }

        // 5. Proximity / Geo-Distance filter (lat, lng, radius)
        if (isset($filters['lat'], $filters['lng']) && is_numeric($filters['lat']) && is_numeric($filters['lng'])) {
            $lat = (float) $filters['lat'];
            $lng = (float) $filters['lng'];
            $radiusKm = isset($filters['radius']) && is_numeric($filters['radius'])
                ? (float) $filters['radius']
                : 25.0;

            $query->nearLocation($lat, $lng, $radiusKm);
        }

        // 6. Minimum Capacity filter (by layout style)
        if (! empty($filters['min_capacity']) && is_numeric($filters['min_capacity'])) {
            $minCap = (int) $filters['min_capacity'];
            $style = ! empty($filters['capacity_style']) ? (string) $filters['capacity_style'] : 'banquet';
            $query->minCapacity($minCap, $style);
        }

        // 7. Pricing filters (GHS converted to pesewas)
        if (! empty($filters['min_price']) && is_numeric($filters['min_price'])) {
            $minPesewas = (int) round(((float) $filters['min_price']) * 100);
            $query->where('store_listings.rental_price_pesewas', '>=', $minPesewas);
        }

        if (! empty($filters['max_price']) && is_numeric($filters['max_price'])) {
            $maxPesewas = (int) round(((float) $filters['max_price']) * 100);
            $query->where('store_listings.rental_price_pesewas', '<=', $maxPesewas);
        }

        // 8. Price Visibility filter (public, on_request)
        if (! empty($filters['price_visibility'])) {
            $query->where('store_listings.price_visibility', (string) $filters['price_visibility']);
        }

        // 9. Required Amenities filter (must be marked as included)
        $amenities = isset($filters['amenities'])
            ? (is_array($filters['amenities']) ? $filters['amenities'] : [$filters['amenities']])
            : [];

        if (! empty($amenities)) {
            foreach ($amenities as $amenitySlug) {
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

        // 10. Sorting & Verified Host priority
        $sort = $filters['sort'] ?? ($vector !== null ? 'ai_match' : 'recommended');

        $hasShopsJoin = collect($query->getQuery()->joins ?? [])->contains(fn ($j): bool => str_contains($j->table, 'shops'));

        match ($sort) {
            'ai_match' => $vector !== null
                ? $query->orderBySimilarity($vector)
                : $query->orderByDesc('store_listings.created_at'),
            'price_asc' => $query->orderBy('store_listings.rental_price_pesewas', 'asc'),
            'price_desc' => $query->orderBy('store_listings.rental_price_pesewas', 'desc'),
            'capacity_desc' => $query->orderByRaw("COALESCE((store_listings.capacity_breakdown->>'banquet')::int, (store_listings.capacity_breakdown->>'theater')::int, 0) DESC"),
            'newest' => $query->orderByDesc('store_listings.created_at'),
            default => (function () use ($query, $hasShopsJoin): Builder {
                if (! $hasShopsJoin) {
                    $query->leftJoin('shops', 'store_listings.shop_id', '=', 'shops.id');
                }

                return $query
                    ->orderByRaw("CASE WHEN shops.verification_status = 'verified' THEN 0 ELSE 1 END")
                    ->orderBy('store_listings.sort_order')
                    ->orderByDesc('store_listings.created_at');
            })(),
        };

        if (! $query->getQuery()->columns) {
            $query->select('store_listings.*');
        }

        $paginator = $query->paginate($perPage)->withQueryString();

        if ($vector !== null) {
            $paginator->getCollection()->transform(function (StoreListing $item): StoreListing {
                $score = isset($item->similarity_score) ? (float) $item->similarity_score : 0.0;
                $percentage = (int) round(max(0.0, min(1.0, $score)) * 100);
                $item->setAttribute('similarity_percentage', $percentage);

                return $item;
            });
        }

        return $paginator;
    }
}
