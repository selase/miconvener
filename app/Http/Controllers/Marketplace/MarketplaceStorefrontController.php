<?php

declare(strict_types=1);

namespace App\Http\Controllers\Marketplace;

use App\Http\Controllers\Controller;
use App\Models\Shop;
use App\Models\StoreListing;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class MarketplaceStorefrontController extends Controller
{
    public function show(Request $request, string $merchant_slug): Response
    {
        /** @var Shop $shop */
        $shop = Shop::query()
            ->with(['tenant'])
            ->where('slug', $merchant_slug)
            ->where('is_active', true)
            ->firstOrFail();

        $spaces = $shop->listings()
            ->with(['primaryMedia', 'amenities.amenity'])
            ->where('status', StoreListing::STATUS_PUBLISHED)
            ->orderBy('sort_order')
            ->orderByDesc('created_at')
            ->get();

        return Inertia::render('Public/Marketplace/Storefront/Show', [
            'shop' => $shop,
            'spaces' => $spaces,
        ]);
    }
}
