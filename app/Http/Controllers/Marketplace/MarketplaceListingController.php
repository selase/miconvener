<?php

declare(strict_types=1);

namespace App\Http\Controllers\Marketplace;

use App\Http\Controllers\Controller;
use App\Models\StoreAmenity;
use App\Models\StoreListing;
use App\Services\Marketplace\MarketplaceSearchService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class MarketplaceListingController extends Controller
{
    public function __construct(private readonly MarketplaceSearchService $searchService) {}

    public function index(Request $request): Response
    {
        $filters = $request->only([
            'q',
            'city',
            'region',
            'lat',
            'lng',
            'radius',
            'min_capacity',
            'capacity_style',
            'min_price',
            'max_price',
            'price_visibility',
            'amenities',
            'sort',
        ]);

        $venues = $this->searchService->searchVenues($filters, 12);

        $amenities = StoreAmenity::query()
            ->orderBy('category')
            ->orderBy('sort_order')
            ->get();

        return Inertia::render('Public/Marketplace/Venues/Index', [
            'venues' => $venues,
            'amenities' => $amenities,
            'filters' => $filters,
        ]);
    }

    public function show(Request $request, string $slug): Response
    {
        /** @var StoreListing $listing */
        $listing = StoreListing::query()
            ->with(['shop', 'primaryMedia', 'media', 'amenities.amenity'])
            ->where('listing_kind', StoreListing::KIND_VENUE)
            ->where('slug', $slug)
            ->where('status', StoreListing::STATUS_PUBLISHED)
            ->whereHas('shop', function ($q): void {
                $q->where('is_active', true);
            })
            ->firstOrFail();

        // Other spaces from this venue host
        $otherSpaces = StoreListing::query()
            ->with(['shop', 'primaryMedia'])
            ->where('shop_id', $listing->shop_id)
            ->where('id', '!=', $listing->id)
            ->where('status', StoreListing::STATUS_PUBLISHED)
            ->take(3)
            ->get();

        return Inertia::render('Public/Marketplace/Venues/Show', [
            'venue' => $listing,
            'otherSpaces' => $otherSpaces,
        ]);
    }
}
