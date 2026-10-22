<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant\Store;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\Store\AdjustMerchandiseStockRequest;
use App\Http\Requests\Tenant\Store\ChangeMerchandiseStateRequest;
use App\Http\Requests\Tenant\Store\SaveMerchandiseRequest;
use App\Http\Requests\Tenant\Store\UploadMerchandisePhotoRequest;
use App\Models\StoreListing;
use App\Models\StoreListingMedia;
use App\Models\StoreStockMovement;
use App\Models\StoreVariant;
use App\Models\Tenant;
use App\Services\Marketplace\MerchandiseCatalog;
use App\Services\Marketplace\MerchandiseInventory;
use App\Services\Marketplace\MerchandiseMediaStorage;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class MerchandiseCatalogController extends Controller
{
    public function __construct(private readonly MerchandiseCatalog $catalog) {}

    public function index(Request $request): Response
    {
        $tenant = $this->tenant();
        $this->catalog->authorize($tenant, $request->user());
        $items = $this->catalog->owned($tenant)->with(['merchandise', 'variants'])->latest('updated_at')->paginate(20);

        return Inertia::render('Tenant/Store/Catalog/Index', ['items' => $items->through(fn (StoreListing $listing): array => $this->payload($listing, false)),
            'shop' => $tenant->shop === null ? null : ['name' => $tenant->shop->name, 'slug' => $tenant->shop->slug], 'checkoutEnabled' => false]);
    }

    public function show(Request $request, string $subdomain, string $listing): Response
    {
        $tenant = $this->tenant();
        $this->catalog->authorize($tenant, $request->user());
        $item = $this->catalog->owned($tenant, $listing)->firstOrFail();

        return Inertia::render('Tenant/Store/Catalog/Form', ['item' => $this->payload($item), 'checkoutEnabled' => false]);
    }

    public function store(SaveMerchandiseRequest $request): JsonResponse
    {
        $item = $this->catalog->save($this->tenant(), $request->user(), null, $request->validated());

        return response()->json(['listing' => $this->payload($item)], 201);
    }

    public function update(SaveMerchandiseRequest $request, string $subdomain, string $listing): JsonResponse
    {
        $tenant = $this->tenant();
        $this->catalog->authorize($tenant, $request->user());
        $item = $this->catalog->save($tenant, $request->user(), $this->catalog->owned($tenant, $listing)->firstOrFail(), $request->validated());

        return response()->json(['listing' => $this->payload($item)]);
    }

    public function publish(ChangeMerchandiseStateRequest $request, string $subdomain, string $listing): JsonResponse
    {
        return $this->state($request, $listing, false);
    }

    public function archive(ChangeMerchandiseStateRequest $request, string $subdomain, string $listing): JsonResponse
    {
        return $this->state($request, $listing, true);
    }

    public function adjust(AdjustMerchandiseStockRequest $request, string $subdomain, string $listing, string $variant, MerchandiseInventory $inventory): JsonResponse
    {
        $tenant = $this->tenant();
        $this->catalog->authorize($tenant, $request->user());
        $item = $this->catalog->owned($tenant, $listing)->firstOrFail();
        $data = $request->validated();
        $movement = $inventory->adjust($item->variants()->findOrFail($variant), (int) $data['delta'], $data['reason'], $data['idempotency_key'], $request->user());

        return response()->json(['movement' => ['id' => $movement->id, 'balance_after' => $movement->balance_after], 'listing' => $this->payload($item)]);
    }

    public function photo(UploadMerchandisePhotoRequest $request, string $subdomain, string $listing, MerchandiseMediaStorage $storage): JsonResponse
    {
        $tenant = $this->tenant();
        $this->catalog->authorize($tenant, $request->user());
        $item = $this->catalog->owned($tenant, $listing)->firstOrFail();
        $photo = $storage->put($tenant, $item, $request->file('photo'));

        return response()->json(['photo' => ['id' => $photo->id, 'url' => $photo->url], 'listing' => $this->payload($item)], 201);
    }

    private function state(ChangeMerchandiseStateRequest $request, string $listing, bool $archive): JsonResponse
    {
        $tenant = $this->tenant();
        $this->catalog->authorize($tenant, $request->user());
        $item = $this->catalog->owned($tenant, $listing)->firstOrFail();
        $updated = $archive ? $this->catalog->archive($tenant, $request->user(), $item) : $this->catalog->publish($tenant, $request->user(), $item);

        return response()->json(['listing' => $this->payload($updated)]);
    }

    /** @return array<string,mixed> */
    private function payload(StoreListing $listing, bool $history = true): array
    {
        $listing->loadMissing(['merchandise', 'variants']);
        $details = $listing->merchandise;
        $photos = $history ? StoreListingMedia::withoutGlobalScope('merchandise')->where('listing_id', $listing->id)->photos()->approved()->orderBy('sort_order')->get()
            ->map(fn (StoreListingMedia $photo): array => ['id' => $photo->id, 'url' => $photo->url])->all() : [];
        $movements = $history ? StoreStockMovement::query()->whereIn('variant_id', $listing->variants->pluck('id'))->latest()->limit(100)->get()
            ->map(fn (StoreStockMovement $movement): array => ['id' => $movement->id, 'variant_id' => $movement->variant_id, 'delta' => $movement->delta,
                'balance_after' => $movement->balance_after, 'reason' => $movement->reason, 'at' => $movement->created_at?->toIso8601String(), 'actor' => $movement->actor_name])->all() : [];

        return ['id' => $listing->id, 'title' => $listing->title, 'slug' => $listing->slug, 'description' => $listing->description, 'status' => $listing->status,
            'taken_down' => $listing->taken_down_at !== null, 'archived' => $details?->archived_at !== null,
            'visibility' => $details?->visibility, 'currency' => $details?->currency, 'collection_instructions' => $details?->collection_instructions,
            'variants' => $listing->variants->map(fn (StoreVariant $variant): array => ['id' => $variant->id, 'sku' => $variant->sku,
                'options' => $variant->options, 'price_pesewas' => $variant->price_pesewas, 'on_hand' => $variant->on_hand, 'is_active' => $variant->is_active])->all(),
            'photos' => $photos, 'movements' => $movements];
    }

    private function tenant(): Tenant
    {
        return app(TenantContext::class)->getTenant() ?? abort(404);
    }
}
