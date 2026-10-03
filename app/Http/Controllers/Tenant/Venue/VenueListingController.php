<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant\Venue;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\Venue\StoreVenueListingRequest;
use App\Models\Shop;
use App\Models\StoreAmenity;
use App\Models\StoreListing;
use App\Models\StoreListingAmenity;
use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

final class VenueListingController extends Controller
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    public function index(Request $request, string $subdomain): Response
    {
        Gate::authorize('manage venue');

        /** @var Tenant $tenant */
        $tenant = $this->tenantContext->getTenant();
        $shop = $tenant->shop;

        if (! $shop) {
            $shop = Shop::create([
                'tenant_id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => Str::slug($tenant->name),
                'email' => $tenant->email ?? '',
                'phone' => $tenant->phone_number ?? '',
                'address' => $tenant->address ?? '',
                'city' => $tenant->city ?? 'Accra',
                'region' => $tenant->state ?? 'Greater Accra',
            ]);
        }

        $spaces = $shop->listings()
            ->with(['primaryMedia', 'amenities.amenity'])
            ->orderBy('sort_order')
            ->orderByDesc('created_at')
            ->paginate(15);

        return Inertia::render('Tenant/Venue/Spaces/Index', [
            'shop' => $shop,
            'spaces' => $spaces,
        ]);
    }

    public function create(Request $request, string $subdomain): Response
    {
        Gate::authorize('manage venue');

        $amenities = StoreAmenity::query()
            ->orderBy('category')
            ->orderBy('sort_order')
            ->get();

        return Inertia::render('Tenant/Venue/Spaces/Form', [
            'space' => null,
            'amenities' => $amenities,
        ]);
    }

    public function store(StoreVenueListingRequest $request, string $subdomain): RedirectResponse
    {
        /** @var Tenant $tenant */
        $tenant = $this->tenantContext->getTenant();
        $shop = $tenant->shop;

        if (! $shop) {
            abort(400, 'Shop profile must be created before listing spaces.');
        }

        $validated = $request->validated();

        $rentalPriceGhs = (float) $validated['rental_price'];
        $securityDepositGhs = isset($validated['security_deposit']) ? (float) $validated['security_deposit'] : null;

        DB::connection('landlord')->transaction(function () use ($shop, $validated, $rentalPriceGhs, $securityDepositGhs): void {
            $listingKind = $validated['listing_kind'] ?? StoreListing::KIND_VENUE;

            $listing = StoreListing::create([
                'shop_id' => $shop->id,
                'listing_kind' => $listingKind,
                'category' => $validated['category'] ?? ($listingKind === StoreListing::KIND_VENUE ? StoreListing::CATEGORY_VENUE : null),
                'title' => $validated['title'],
                'slug' => $validated['slug'],
                'description' => $validated['description'] ?? null,
                'rental_price_pesewas' => (int) round($rentalPriceGhs * 100),
                'pricing_model' => $validated['pricing_model'],
                'price_visibility' => $validated['price_visibility'],
                'security_deposit_pesewas' => $securityDepositGhs !== null ? (int) round($securityDepositGhs * 100) : null,
                'min_order_pesewas' => (int) ($validated['min_order_pesewas'] ?? 0),
                'lead_time_days' => (int) ($validated['lead_time_days'] ?? 1),
                'capacity_breakdown' => $validated['capacity_breakdown'] ?? [],
                'service_scope' => $validated['service_scope'] ?? [],
                'floor_area_sqm' => $validated['floor_area_sqm'] ?? null,
                'ceiling_height_meters' => $validated['ceiling_height_meters'] ?? null,
                'rules_and_policies' => $validated['rules_and_policies'] ?? [],
                'status' => $validated['status'],
            ]);

            if (! empty($validated['amenities'])) {
                foreach ($validated['amenities'] as $amenityData) {
                    StoreListingAmenity::create([
                        'listing_id' => $listing->id,
                        'amenity_id' => $amenityData['amenity_id'],
                        'is_included' => (bool) $amenityData['is_included'],
                        'notes' => $amenityData['notes'] ?? null,
                    ]);
                }
            }
        });

        return redirect()->route('tenant.venue.spaces.index', ['subdomain' => $subdomain])
            ->with('success', 'Venue space created successfully.');
    }

    public function edit(Request $request, string $subdomain, StoreListing $listing): Response
    {
        Gate::authorize('manage venue');
        $this->authorizeListingOwnership($listing);

        $listing->load(['amenities.amenity', 'media']);

        $amenities = StoreAmenity::query()
            ->orderBy('category')
            ->orderBy('sort_order')
            ->get();

        return Inertia::render('Tenant/Venue/Spaces/Form', [
            'space' => $listing,
            'amenities' => $amenities,
        ]);
    }

    public function update(StoreVenueListingRequest $request, string $subdomain, StoreListing $listing): RedirectResponse
    {
        Gate::authorize('manage venue');
        $this->authorizeListingOwnership($listing);

        $validated = $request->validated();
        $rentalPriceGhs = (float) $validated['rental_price'];
        $securityDepositGhs = isset($validated['security_deposit']) ? (float) $validated['security_deposit'] : null;

        DB::connection('landlord')->transaction(function () use ($listing, $validated, $rentalPriceGhs, $securityDepositGhs): void {
            $listing->update([
                'listing_kind' => $validated['listing_kind'] ?? $listing->listing_kind,
                'category' => $validated['category'] ?? $listing->category,
                'title' => $validated['title'],
                'slug' => $validated['slug'],
                'description' => $validated['description'] ?? null,
                'rental_price_pesewas' => (int) round($rentalPriceGhs * 100),
                'pricing_model' => $validated['pricing_model'],
                'price_visibility' => $validated['price_visibility'],
                'security_deposit_pesewas' => $securityDepositGhs !== null ? (int) round($securityDepositGhs * 100) : null,
                'min_order_pesewas' => (int) ($validated['min_order_pesewas'] ?? $listing->min_order_pesewas),
                'lead_time_days' => (int) ($validated['lead_time_days'] ?? $listing->lead_time_days),
                'capacity_breakdown' => $validated['capacity_breakdown'] ?? [],
                'service_scope' => $validated['service_scope'] ?? [],
                'floor_area_sqm' => $validated['floor_area_sqm'] ?? null,
                'ceiling_height_meters' => $validated['ceiling_height_meters'] ?? null,
                'rules_and_policies' => $validated['rules_and_policies'] ?? [],
                'status' => $validated['status'],
            ]);

            // Sync amenities grid
            $listing->amenities()->delete();

            if (! empty($validated['amenities'])) {
                foreach ($validated['amenities'] as $amenityData) {
                    StoreListingAmenity::create([
                        'listing_id' => $listing->id,
                        'amenity_id' => $amenityData['amenity_id'],
                        'is_included' => (bool) $amenityData['is_included'],
                        'notes' => $amenityData['notes'] ?? null,
                    ]);
                }
            }
        });

        return redirect()->route('tenant.venue.spaces.index', ['subdomain' => $subdomain])
            ->with('success', 'Venue space updated successfully.');
    }

    public function destroy(Request $request, string $subdomain, StoreListing $listing): RedirectResponse
    {
        Gate::authorize('manage venue');
        $this->authorizeListingOwnership($listing);

        $listing->delete();

        return redirect()->route('tenant.venue.spaces.index', ['subdomain' => $subdomain])
            ->with('success', 'Venue space deleted successfully.');
    }

    private function authorizeListingOwnership(StoreListing $listing): void
    {
        /** @var Tenant $tenant */
        $tenant = $this->tenantContext->getTenant();
        $shop = $tenant->shop;

        if (! $shop || $listing->shop_id !== $shop->id) {
            abort(403, 'Unauthorized access to venue listing.');
        }
    }
}
