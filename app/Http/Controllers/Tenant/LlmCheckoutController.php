<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Contracts\PaymentGateway;
use App\Http\Controllers\Controller;
use App\Services\Pricing\ChargeQuote;
use App\Services\Pricing\CheckoutTerms;
use App\Services\Pricing\PriceBook;
use App\Services\Pricing\VisitorCurrency;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Inertia\Inertia;

final class LlmCheckoutController extends Controller
{
    public function store(Request $request, PaymentGateway $gateway, TenantContext $tenantContext)
    {
        $this->authorize('manage billing');

        $tenant = $tenantContext->getTenant();
        if (! $tenant instanceof \App\Models\Tenant) {
            abort(404, 'Tenant not found');
        }

        $validated = $request->validate([
            'pack' => 'required|string',
        ]);

        $packKey = $validated['pack'];
        $packs = Config::get('llm.token_packs', []);

        if (! isset($packs[$packKey])) {
            return back()->with('error', __('Invalid token pack selected.'));
        }

        $pack = $packs[$packKey];
        $customerId = $this->getOrCreateCustomerId($request, $gateway, $tenant);

        $price = app(PriceBook::class)->tokenPack($packKey, app(VisitorCurrency::class)->resolve($request, $tenant));
        $quote = app(ChargeQuote::class)->for($price);
        $terms = CheckoutTerms::for($tenant, $price, app(PriceBook::class)->tokenPack($packKey, 'USD'));
        $redirectUrl = route('tenant.llm-usage.index');

        $checkoutUrl = $gateway->createOneTimeCheckoutSession(
            $customerId,
            $quote->ghsMinor(),
            $pack['currency'],
            $redirectUrl,
            [
                'tenant_id' => $tenant->id,
                'pack_key' => $packKey,
                'type' => 'llm_token_purchase',
                'source' => config('services.paystack.metadata_source'),
                'description' => "Purchase: {$pack['name']} ({$pack['tokens']} tokens)",
                ...$terms->metadata($quote),
            ]
        );

        return Inertia::location($checkoutUrl);
    }

    private function getOrCreateCustomerId(Request $request, PaymentGateway $gateway, \App\Models\Tenant $tenant): string
    {
        $driver = config('services.payment.default', 'stripe');
        $metaKey = "{$driver}_id";
        $customerId = $tenant->meta[$metaKey] ?? null;

        if (! $customerId) {
            $customerId = $gateway->createCustomer($request->user()->email, $tenant->name);
            $meta = $tenant->meta ?? [];
            $meta[$metaKey] = $customerId;
            $tenant->update(['meta' => $meta]);
        }

        return $customerId;
    }
}
