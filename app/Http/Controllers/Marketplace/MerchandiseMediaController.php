<?php

declare(strict_types=1);

namespace App\Http\Controllers\Marketplace;

use App\Http\Controllers\Controller;
use App\Models\StoreListing;
use App\Models\StoreListingMedia;
use App\Models\User;
use App\Scopes\MerchandiseVisibilityScope;
use App\Services\Marketplace\MerchandiseAccess;
use App\Services\Marketplace\MerchandiseMediaStorage;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class MerchandiseMediaController extends Controller
{
    public function show(Request $request, string $slug, string $media, MerchandiseAccess $access, MerchandiseMediaStorage $storage): StreamedResponse
    {
        $listing = StoreListing::withoutGlobalScope(MerchandiseVisibilityScope::class)->where('listing_kind', StoreListing::KIND_MERCHANDISE)->where('slug', $slug)->firstOrFail();
        $owner = $request->user() instanceof User && $access->canManage($listing, $request->user());
        abort_unless($owner || $access->canRead($listing, $request->session()), 404);
        $photo = StoreListingMedia::withoutGlobalScope('merchandise')->where('listing_id', $listing->id)
            ->where('media_type', StoreListingMedia::TYPE_PHOTO)->where('status', StoreListingMedia::STATUS_APPROVED)->findOrFail($media);

        return $storage->stream($photo);
    }
}
