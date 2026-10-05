<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant\Venue;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\Venue\UpdateVenueProfileRequest;
use App\Models\Shop;
use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

final class VenueProfileController extends Controller
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    public function show(Request $request, string $subdomain): Response
    {
        Gate::authorize('manage venue');

        /** @var Tenant $tenant */
        $tenant = $this->tenantContext->getTenant();

        $shop = $tenant->shop ?? Shop::create([
            'tenant_id' => $tenant->id,
            'name' => $tenant->name,
            'slug' => Str::slug($tenant->name),
            'email' => $tenant->email ?? '',
            'phone' => $tenant->phone_number ?? '',
            'address' => $tenant->address ?? '',
            'city' => $tenant->city ?? 'Accra',
            'region' => $tenant->state ?? 'Greater Accra',
        ]);

        return Inertia::render('Tenant/Venue/Profile/Show', [
            'shop' => $shop,
        ]);
    }

    public function update(UpdateVenueProfileRequest $request, string $subdomain): RedirectResponse
    {
        /** @var Tenant $tenant */
        $tenant = $this->tenantContext->getTenant();
        $shop = $tenant->shop;

        if (! $shop) {
            $shop = new Shop(['tenant_id' => $tenant->id]);
        }

        $validated = $request->validated();
        $shop->fill($validated);
        $shop->save();

        return redirect()->route('tenant.venue.profile', ['subdomain' => $subdomain])
            ->with('success', 'Venue profile updated successfully.');
    }

    public function submitVerification(Request $request, string $subdomain): RedirectResponse
    {
        Gate::authorize('manage venue');

        /** @var Tenant $tenant */
        $tenant = $this->tenantContext->getTenant();
        $shop = $tenant->shop;

        if (! $shop) {
            return redirect()->route('tenant.venue.profile', ['subdomain' => $subdomain])
                ->with('error', 'Please complete your venue profile before requesting verification.');
        }

        // Verification is a paid review (GHS 150 a year).
        if (! $shop->hasActivePromotion(\App\Models\TenantAddon::TYPE_SHOP_VERIFICATION)) {
            return redirect()->route('tenant.venue.profile', ['subdomain' => $subdomain])
                ->with('error', 'Buy the Verified business add-on (Billing → Add-ons) before asking for verification.');
        }

        $shop->update([
            'verification_status' => Shop::VERIFICATION_PENDING,
        ]);

        return redirect()->route('tenant.venue.profile', ['subdomain' => $subdomain])
            ->with('success', 'Verification request submitted. Our team will review your credentials.');
    }
}
