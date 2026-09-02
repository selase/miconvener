<?php

declare(strict_types=1);

namespace App\Http\Controllers\Billing;

use App\Contracts\PaymentGateway;
use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Package;
use App\Services\Billing\SubscriptionProvisioningService;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class CheckoutController extends Controller
{
    public function store(
        Request $request,
        PaymentGateway $gateway,
        TenantContext $tenantContext,
        SubscriptionProvisioningService $provisioningService
    ): RedirectResponse {
        $tenant = $tenantContext->getTenant();

        if (!$tenant instanceof \App\Models\Tenant) {
            abort(404, 'Tenant not found');
        }

        // Handle Invoice Payment
        if ($request->has('invoice_id')) {
            return $this->handleInvoiceCheckout($request, $gateway, $tenant);
        }

        // Handle Plan Subscription
        return $this->handlePlanCheckout($request, $gateway, $tenant, $provisioningService);
    }

    private function handleInvoiceCheckout(Request $request, PaymentGateway $gateway, mixed $tenant): RedirectResponse
    {
        $invoice = Invoice::where('tenant_id', $tenant->id)
            ->where('status', Invoice::STATUS_ISSUED)
            ->findOrFail($request->input('invoice_id'));

        $customerId = $this->getOrCreateCustomerId($request, $gateway, $tenant);
        $amount = (int) round((float) $invoice->total * 100);
        $redirectUrl = route('billing.invoices.show', $invoice->id);

        $checkoutUrl = $gateway->createOneTimeCheckoutSession(
            $customerId,
            $amount,
            $invoice->currency ?: 'USD',
            $redirectUrl,
            [
                'invoice_id' => $invoice->id,
                'type' => 'invoice_payment',
                'description' => "Invoice #{$invoice->number}",
            ]
        );

        return redirect($checkoutUrl);
    }

    private function handlePlanCheckout(
        Request $request,
        PaymentGateway $gateway,
        mixed $tenant,
        SubscriptionProvisioningService $provisioningService
    ): RedirectResponse {
        $validated = $request->validate([
            'plan' => 'required|string|exists:packages,slug',
            'interval' => 'sometimes|in:month,year',
        ]);

        $slug = $validated['plan'];
        $interval = $validated['interval'] ?? 'month';

        /** @var Package $newPackage */
        $newPackage = Package::where('slug', $slug)->firstOrFail();

        // Free plan — no payment needed, provision immediately
        if ($newPackage->isFree()) {
            $provisioningService->switchToFree($tenant);

            return redirect()->route('billing.index', ['subdomain' => $tenant->slug])
                ->with('success', 'You are now on the Free plan.');
        }

        // Determine current package for upgrade/downgrade decision
        $currentPackage = $tenant->package_id ? Package::find($tenant->package_id) : null;
        $currentSortOrder = $currentPackage?->sort_order ?? 0;

        // Downgrade: schedule it for period end, no payment needed
        if ($currentPackage && $newPackage->sort_order < $currentSortOrder) {
            $provisioningService->downgrade($tenant, $newPackage);

            return redirect()->route('billing.index', ['subdomain' => $tenant->slug])
                ->with('info', "Your plan will downgrade to {$newPackage->name} at the end of your billing period.");
        }

        // Dev bypass: provision immediately without hitting Paystack
        if (config('services.payment.dev_bypass', false)) {
            $provisioningService->provision($tenant, [
                'package_id' => $newPackage->id,
                'provider' => 'dev_bypass',
                'provider_subscription_id' => 'dev_'.uniqid(),
                'provider_plan_id' => $newPackage->slug.'_'.$interval,
                'status' => 'active',
                'current_period_end' => $interval === 'year' ? now()->addYear() : now()->addMonth(),
                'amount_paid' => 0,
                'currency' => 'ghs',
                'transaction_id' => 'dev_tx_'.uniqid(),
            ]);

            if (! $tenant->onboarding_completed_at) {
                return redirect()->route('tenant.onboarding.wizard', ['subdomain' => $tenant->slug])
                    ->with('subscription_activated', $newPackage->name);
            }

            return redirect()->route('billing.index', ['subdomain' => $tenant->slug])
                ->with('success', "Welcome to {$newPackage->name}! Your subscription is now active.");
        }

        // Upgrade or new subscription: one-time Paystack transaction with plan metadata
        $amount = $interval === 'year'
            ? (int) round((float) $newPackage->yearly_price * 100)
            : (int) round((float) $newPackage->price * 100);

        $customerId = $this->getOrCreateCustomerId($request, $gateway, $tenant);

        $checkoutUrl = $gateway->createOneTimeCheckoutSession(
            $customerId,
            $amount,
            config('services.paystack.currency', 'GHS'),
            route('billing.callback'),
            [
                'type' => 'plan_subscription',
                'plan_slug' => $newPackage->slug,
                'interval' => $interval,
                'tenant_id' => $tenant->id,
            ]
        );

        return redirect($checkoutUrl);
    }

    private function getOrCreateCustomerId(Request $request, PaymentGateway $gateway, mixed $tenant): string
    {
        $driver = config('services.payment.default', 'paystack');
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
