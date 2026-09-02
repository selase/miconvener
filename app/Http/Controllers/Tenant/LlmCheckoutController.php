<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Contracts\PaymentGateway;
use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;

final class LlmCheckoutController extends Controller
{
    public function store(Request $request, PaymentGateway $gateway, TenantContext $tenantContext)
    {
        $tenant = $tenantContext->getTenant();
        if (!$tenant instanceof \App\Models\Tenant) {
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

        $amount = (int) round((float) $pack['price'] * 100);
        $redirectUrl = route('tenant.llm-usage.index');

        $checkoutUrl = $gateway->createOneTimeCheckoutSession(
            $customerId,
            $amount,
            $pack['currency'],
            $redirectUrl,
            [
                'tenant_id' => $tenant->id,
                'pack_key' => $packKey,
                'type' => 'llm_token_purchase',
                'description' => "Purchase: {$pack['name']} ({$pack['tokens']} tokens)",
            ]
        );

        return redirect($checkoutUrl);
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
