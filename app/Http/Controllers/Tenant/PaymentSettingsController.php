<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\TenantPaymentGateway;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

final class PaymentSettingsController extends Controller
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    /**
     * Display the merchant payment settings page.
     */
    public function index(): View
    {
        $this->authorize('manage organization settings');
        $tenant = $this->tenantContext->getTenant();

        // Check if commerce feature is enabled
        if (! $tenant->featureEnabled('commerce')) {
            abort(403, 'The Commerce feature is not enabled for your organization.');
        }

        $gateways = TenantPaymentGateway::where('tenant_id', $tenant->id)->get();
        $stripe = $gateways->where('provider', 'stripe')->first();
        $paystack = $gateways->where('provider', 'paystack')->first();

        $breadcrumbs = [
            ['link' => route('tenant.dashboard'), 'name' => __('Dashboard')],
            ['link' => route('tenant.settings.index'), 'name' => __('Settings')],
            ['link' => '#', 'name' => __('Merchant Payments')],
        ];

        return view('tenant.settings.payments', [
            'tenant' => $tenant,
            'stripe' => $stripe,
            'paystack' => $paystack,
            'breadcrumbs' => $breadcrumbs,
        ]);
    }

    /**
     * Update/Store merchant payment settings.
     */
    public function update(Request $request): RedirectResponse
    {
        $this->authorize('manage organization settings');
        $tenant = $this->tenantContext->getTenant();

        if (! $tenant->featureEnabled('commerce')) {
            abort(403);
        }

        $validated = $request->validate([
            'provider' => ['required', 'string', 'in:stripe,paystack'],
            'api_key' => ['required', 'string'],
            'public_key' => ['nullable', 'string'],
            'webhook_secret' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        TenantPaymentGateway::updateOrCreate(
            [
                'tenant_id' => $tenant->id,
                'provider' => $validated['provider'],
            ],
            [
                'api_key_encrypted' => $validated['api_key'],
                'public_key_encrypted' => $validated['public_key'],
                'webhook_secret_encrypted' => $validated['webhook_secret'],
                'is_active' => $request->boolean('is_active', true),
            ]
        );

        return back()->with([
            'status' => 'success',
            'message' => __(':provider settings updated successfully.', ['provider' => ucfirst((string) $validated['provider'])]),
        ]);
    }

    /**
     * Switch the tenant's settlement mode between platform_default and own_gateway.
     */
    public function updateSettlementMode(Request $request): RedirectResponse
    {
        $this->authorize('manage organization settings');
        $tenant = $this->tenantContext->getTenant();

        $validated = $request->validate([
            'settlement_mode' => ['required', 'string', 'in:'.Tenant::SETTLEMENT_MODE_PLATFORM_DEFAULT.','.Tenant::SETTLEMENT_MODE_OWN_GATEWAY],
        ]);

        if ($validated['settlement_mode'] === Tenant::SETTLEMENT_MODE_PLATFORM_DEFAULT && ! config('services.settlement.paystack.secret_key')) {
            return back()->with('error', 'Platform-default settlement is not available yet — the platform has not configured its own settlement credentials.');
        }

        $tenant->update(['settlement_mode' => $validated['settlement_mode']]);

        return back()->with([
            'status' => 'success',
            'message' => 'Settlement mode updated.',
        ]);
    }

    /**
     * Application-superadmin only: set a tenant's commission terms from the
     * UI, writing the same columns php artisan events:set-platform-fee writes.
     * The CLI command remains for scripted and bulk use.
     *
     * Authorization is the access-superadmin-dashboard gate, which requires
     * the global Superadmin role with a null tenant_id. It deliberately does
     * NOT key off the email column: tenant admins can set team members'
     * email addresses, and users.email is unique globally rather than per
     * tenant, so an address allowlist is a value the tenant controls.
     */
    public function updatePlatformFee(Request $request): RedirectResponse
    {
        Gate::authorize('access-superadmin-dashboard');

        $tenant = $this->tenantContext->getTenant();

        $validated = $request->validate([
            'platform_fee_percentage' => ['required', 'numeric', 'min:0', 'max:100'],
            /*
             * Minor units, matching the CLI. Leave it empty to clear the
             * override so the package default applies.
             *
             * Zero is refused rather than accepted: a cap of nothing waives
             * the commission entirely, which is almost never what someone
             * typing "0" for "no cap" means. A deliberate waiver is a zero
             * percentage, which reads as one.
             */
            'platform_fee_cap_amount' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ]);

        $attributes = ['platform_fee_percentage' => $validated['platform_fee_percentage']];

        if ($request->has('platform_fee_cap_amount')) {
            $attributes['platform_fee_cap_amount'] = $validated['platform_fee_cap_amount'];
        }

        $tenant->update($attributes);

        return back()->with([
            'status' => 'success',
            'message' => 'Platform fee updated.',
        ]);
    }
}
