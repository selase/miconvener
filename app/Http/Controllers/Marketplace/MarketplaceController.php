<?php

declare(strict_types=1);

namespace App\Http\Controllers\Marketplace;

use App\Http\Controllers\Controller;
use App\Models\Shop;
use App\Models\StoreListing;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class MarketplaceController extends Controller
{
    public function index(Request $request): Response
    {
        // Curated featured venues across major event hubs in Ghana
        $featuredVenues = StoreListing::query()
            ->with(['shop', 'primaryMedia'])
            ->where('listing_kind', StoreListing::KIND_VENUE)
            ->where('status', StoreListing::STATUS_PUBLISHED)
            ->whereHas('shop', function ($q): void {
                $q->where('is_active', true);
            })
            ->leftJoin('shops', 'store_listings.shop_id', '=', 'shops.id')
            ->orderByRaw("CASE WHEN shops.verification_status = 'verified' THEN 0 ELSE 1 END")
            ->select('store_listings.*')
            ->orderBy('store_listings.sort_order')
            ->orderByDesc('store_listings.created_at')
            ->take(6)
            ->get();

        // Featured verified venues / merchants
        $featuredMerchants = Shop::query()
            ->withCount(['listings' => function ($q): void {
                $q->where('status', StoreListing::STATUS_PUBLISHED);
            }])
            ->where('is_active', true)
            ->orderByRaw("CASE WHEN verification_status = 'verified' THEN 0 ELSE 1 END")
            ->orderByDesc('listings_count')
            ->take(4)
            ->get();

        return Inertia::render('Public/Marketplace/Index', [
            'featuredVenues' => $featuredVenues,
            'featuredMerchants' => $featuredMerchants,
        ]);
    }
}
