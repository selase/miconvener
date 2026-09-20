<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class BillingSettingsController extends Controller
{
    public function index(TenantContext $tenantContext): Response
    {
        $this->authorize('manage billing');
        $tenant = $tenantContext->getTenant();

        return Inertia::render('Tenant/Settings/Billing', [
            'billingEmail' => $tenant->meta['billing_email'] ?? $tenant->email,
            'taxId' => $tenant->meta['tax_id'] ?? '',
            'billingAddress' => $tenant->meta['billing_address'] ?? $tenant->address ?? '',
        ]);
    }

    public function update(Request $request, TenantContext $tenantContext): RedirectResponse
    {
        $this->authorize('manage billing');
        $tenant = $tenantContext->getTenant();

        $validated = $request->validate([
            'billing_email' => ['required', 'email'],
            'tax_id' => ['nullable', 'string', 'max:50'],
            'billing_address' => ['nullable', 'string', 'max:255'],
        ]);

        $meta = $tenant->meta ?? [];
        $meta['billing_email'] = $validated['billing_email'];
        $meta['tax_id'] = $validated['tax_id'];
        $meta['billing_address'] = $validated['billing_address'];

        $tenant->update(['meta' => $meta]);

        return back()->with('success', 'Billing settings updated successfully.');
    }
}
