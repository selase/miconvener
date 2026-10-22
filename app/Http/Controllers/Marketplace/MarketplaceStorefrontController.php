<?php

declare(strict_types=1);

namespace App\Http\Controllers\Marketplace;

use App\Http\Controllers\Controller;
use App\Models\Shop;
use App\Models\StoreListing;
use App\Scopes\MerchandiseVisibilityScope;
use App\Services\Events\PlatformAttendeeVerification;
use App\Services\Marketplace\CommerceCatalogRollout;
use App\Services\Marketplace\MerchandiseAccess;
use App\Services\Marketplace\MerchandisePayload;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

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
            ->where('listing_kind', '!=', StoreListing::KIND_MERCHANDISE)->with(['primaryMedia', 'amenities.amenity'])
            ->where('status', StoreListing::STATUS_PUBLISHED)
            ->orderBy('sort_order')
            ->orderByDesc('created_at')
            ->get();

        $enabled = app(CommerceCatalogRollout::class)->enabled($shop->tenant);
        $merchandise = collect();
        $merchandiseLinks = [];
        if ($enabled) {
            $member = app(MerchandiseAccess::class)->canReadMembersFrom($shop->tenant, $request->session());
            $query = $shop->listings()->where('listing_kind', StoreListing::KIND_MERCHANDISE)->catalogAvailable();
            if ($member) {
                $query->withoutGlobalScope(MerchandiseVisibilityScope::class);
            }
            $page = $query->with(['shop', 'merchandise', 'variants', 'media' => fn ($media) => $media->withoutGlobalScope('merchandise')->photos()->approved()])
                ->orderBy('sort_order')->orderBy('title')->orderBy('id')->paginate(24, ['*'], 'items_page')->withQueryString();
            $merchandiseLinks = $page->linkCollection()->all();
            $merchandise = $page->getCollection()->map(fn (StoreListing $listing): array => app(MerchandisePayload::class)->forViewer($listing));
        }
        $data = ['shop' => $shop->only(['name', 'slug', 'description', 'logo_path', 'cover_image_path', 'email', 'phone', 'address', 'city', 'region', 'verification_status', 'average_rating', 'reviews_count']),
            'spaces' => $spaces, 'merchandise' => $merchandise, 'merchandiseLinks' => $merchandiseLinks, 'catalogEnabled' => $enabled,
            'memberAccessUrl' => route('attendee.my', ['organiser' => $shop->tenant->slug, 'section' => 'communities']),
            'verifiedUntil' => $request->session()->get(PlatformAttendeeVerification::SESSION_KEY.'.expires_at')];
        $response = $request->expectsJson() ? response()->json($data) : Inertia::render('Public/Marketplace/Storefront/Show', $data)->toResponse($request);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
