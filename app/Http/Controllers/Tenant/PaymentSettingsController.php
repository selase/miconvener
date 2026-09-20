<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\UpdatePaymentGatewayRequest;
use App\Models\Tenant;
use App\Models\TenantPaymentGateway;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

final class PaymentSettingsController extends Controller
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    /**
     * Display the merchant payment settings page.
     */
    public function index(): Response
    {
        $this->authorize('manage payment settings');
        $tenant = $this->tenantContext->getTenant();

        // The same check that lets a tenant sell paid tickets: whoever can take
        // payment can configure how they are paid, and no one else.
        if (! $tenant->handlesTicketMoney()) {
            abort(403, 'Your plan does not include paid tickets.');
        }

        $gateways = TenantPaymentGateway::where('tenant_id', $tenant->id)->get()->keyBy('provider');
        $canSetPlatformFee = Gate::allows('access-superadmin-dashboard');

        return Inertia::render('Tenant/Settings/Payments', [
            'settlementMode' => $tenant->settlement_mode,
            'platformSettlementAvailable' => (bool) config('services.settlement.paystack.secret_key'),
            'gateways' => collect(['stripe', 'paystack'])->mapWithKeys(fn (string $provider): array => [
                $provider => $this->gatewaySummary($gateways->get($provider), $provider, $tenant),
            ]),
            'canSetPlatformFee' => $canSetPlatformFee,
            'platformFee' => $canSetPlatformFee ? [
                'percentage' => $tenant->platform_fee_percentage,
                'cap' => $tenant->platform_fee_cap_amount !== null
                    ? number_format($tenant->platform_fee_cap_amount / 100, 2, '.', '')
                    : null,
            ] : null,
        ]);
    }

    /**
     * Update/Store merchant payment settings. A blank secret keeps the saved
     * one: the page never receives secrets, so it cannot send them back.
     */
    public function update(UpdatePaymentGatewayRequest $request): RedirectResponse
    {
        $tenant = $this->tenantContext->getTenant();

        if (! $tenant->handlesTicketMoney()) {
            abort(403, 'Your plan does not include paid tickets.');
        }

        $validated = $request->validated();
        $attributes = [
            'public_key_encrypted' => $validated['public_key'] ?? null,
            'is_active' => $request->boolean('is_active', true),
        ];

        if (filled($validated['api_key'] ?? null)) {
            $attributes['api_key_encrypted'] = $validated['api_key'];
        }

        if (filled($validated['webhook_secret'] ?? null)) {
            $attributes['webhook_secret_encrypted'] = $validated['webhook_secret'];
        }

        TenantPaymentGateway::updateOrCreate(
            ['tenant_id' => $tenant->id, 'provider' => $validated['provider']],
            $attributes
        );

        return back()->with('success', __(':provider settings saved.', ['provider' => ucfirst((string) $validated['provider'])]));
    }

    /**
     * Switch the tenant's settlement mode between platform_default and own_gateway.
     */
    public function updateSettlementMode(Request $request): RedirectResponse
    {
        $this->authorize('manage payment settings');
        $tenant = $this->tenantContext->getTenant();

        $validated = $request->validate([
            'settlement_mode' => ['required', 'string', 'in:'.Tenant::SETTLEMENT_MODE_PLATFORM_DEFAULT.','.Tenant::SETTLEMENT_MODE_OWN_GATEWAY],
        ]);

        if ($validated['settlement_mode'] === Tenant::SETTLEMENT_MODE_PLATFORM_DEFAULT && ! config('services.settlement.paystack.secret_key')) {
            return back()->with('error', 'Platform-default settlement is not available yet — the platform has not configured its own settlement credentials.');
        }

        $tenant->update(['settlement_mode' => $validated['settlement_mode']]);

        return back()->with('success', 'Settlement mode updated.');
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
             * Major units (cedis) — a person typing into a form means GHS 20,
             * not 20 pesewas. Stored in minor units like every other amount.
             * Empty clears the override so the package default applies.
             *
             * A zero cap is refused: it waives the commission entirely, which
             * is almost never what "0" for "no cap" means. A deliberate waiver
             * is a zero percentage, which reads as one.
             */
            'platform_fee_cap' => ['sometimes', 'nullable', 'numeric', 'min:0.01'],
        ]);

        $attributes = ['platform_fee_percentage' => $validated['platform_fee_percentage']];

        if ($request->has('platform_fee_cap')) {
            $attributes['platform_fee_cap_amount'] = $validated['platform_fee_cap'] === null
                ? null
                : (int) round((float) $validated['platform_fee_cap'] * 100);
        }

        $tenant->update($attributes);

        return back()->with('success', 'Platform fee updated.');
    }

    /**
     * What the page may know about a provider: whether it is connected and its
     * public details, never the secret key or webhook secret themselves.
     *
     * @return array{connected: bool, has_webhook_secret: bool, public_key: ?string, is_active: bool, webhook_url: string}
     */
    private function gatewaySummary(?TenantPaymentGateway $gateway, string $provider, Tenant $tenant): array
    {
        return [
            'connected' => $gateway !== null,
            'has_webhook_secret' => filled($gateway?->webhook_secret_encrypted),
            'public_key' => $gateway?->public_key_encrypted,
            'is_active' => (bool) $gateway?->is_active,
            'webhook_url' => route("webhooks.merchant.{$provider}", ['tenant' => $tenant->id]),
        ];
    }
}
