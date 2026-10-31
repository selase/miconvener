<?php

declare(strict_types=1);

namespace App\Http\Controllers\Marketplace;

use App\Http\Controllers\Controller;
use App\Models\StoreListing;
use App\Models\User;
use App\Scopes\MerchandiseVisibilityScope;
use App\Services\Events\PlatformAttendeeVerification;
use App\Services\Marketplace\MerchandiseAccess;
use App\Services\Marketplace\MerchandisePayload;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

final class MerchandiseItemController extends Controller
{
    public function show(Request $request, string $slug, MerchandiseAccess $access, MerchandisePayload $payload): Response
    {
        abort_unless((new StoreListing)->getConnection()->getSchemaBuilder()->hasTable('store_merchandise'), 404);
        $listing = StoreListing::withoutGlobalScope(MerchandiseVisibilityScope::class)->where('listing_kind', StoreListing::KIND_MERCHANDISE)->where('slug', $slug)->firstOrFail();
        $owner = $request->user() instanceof User && $access->canManage($listing, $request->user());
        $customer = $access->canRead($listing, $request->session());
        abort_unless($owner || $customer, 404);
        $data = ['item' => $payload->forViewer($listing), 'preview' => $owner && ! $customer,
            'storefrontUrl' => route('marketplace.storefront.show', ['merchant_slug' => $listing->shop->slug]),
            'verifiedUntil' => $listing->merchandise->visibility === 'members' && ! $owner
                ? $request->session()->get(PlatformAttendeeVerification::SESSION_KEY.'.expires_at') : null];
        $response = $request->expectsJson() ? response()->json($data) : Inertia::render('Public/Marketplace/Items/Show', $data)->toResponse($request);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
