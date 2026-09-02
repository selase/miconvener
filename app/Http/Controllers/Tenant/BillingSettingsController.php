<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class BillingSettingsController extends Controller
{
    public function index(TenantContext $tenantContext): View
    {
        $tenant = $tenantContext->getTenant();

        return view('tenant.settings.billing', [
            'tenant' => $tenant,
            'billingEmail' => $tenant->meta['billing_email'] ?? $tenant->email,
            'taxId' => $tenant->meta['tax_id'] ?? '',
            'billingAddress' => $tenant->meta['billing_address'] ?? $tenant->address,
            'breadcrumbs' => [
                ['name' => 'Billing', 'link' => route('billing.index')],
                ['name' => 'Settings'],
            ],
        ]);
    }

    public function update(Request $request, TenantContext $tenantContext): RedirectResponse
    {
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
