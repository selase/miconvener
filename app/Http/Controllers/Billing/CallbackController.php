<?php

declare(strict_types=1);

namespace App\Http\Controllers\Billing;

use App\Contracts\PaymentGateway;
use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Package;
use App\Models\Tenant;
use App\Services\Billing\BillingNotifier;
use App\Services\Billing\PaymentFulfillmentService;
use App\Services\Billing\SubscriptionProvisioningService;
use App\Services\Tenancy\TenantContext;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;

final class CallbackController extends Controller
{
    public function __invoke(
        Request $request,
        PaymentGateway $gateway,
        TenantContext $tenantContext,
        SubscriptionProvisioningService $provisioningService
    ) {
        $reference = $request->query('session_id') ?? $request->query('reference');

        if (! $reference) {
            $tenant = $tenantContext->getTenant();

            return redirect()->route('billing.index', ['subdomain' => $tenant->slug])
                ->with('error', 'Invalid payment reference.');
        }

        try {
            $result = $gateway->verifyTransaction($reference);

            /*
             * The webhook records every payment under the provider's reference.
             * Paystack's verify response also carries a numeric id; keying on
             * that instead meant neither path recognised the other's record and
             * one payment was fulfilled twice.
             */
            $result['reference'] = (string) ($result['reference'] ?? $result['transaction_id'] ?? $reference);

            if (in_array($result['status'], ['succeeded', 'success', 'paid'], true)) {

                // Invoice payment
                $invoiceId = data_get($result, 'metadata.invoice_id');
                if ($invoiceId) {
                    return $this->handleInvoiceFulfillment((string) $invoiceId, $result, $tenantContext);
                }

                // LLM token purchase
                $type = data_get($result, 'metadata.type');
                if ($type === 'llm_token_purchase') {
                    return $this->handleLlmTokenFulfillment($result, $tenantContext);
                }

                // Metadata-driven plan subscription (no Paystack plan codes required)
                if ($type === 'plan_subscription') {
                    return $this->handlePlanSubscriptionFulfillment($result, $tenantContext, $provisioningService);
                }

                // Legacy: Paystack subscription plan (plan code approach)
                if (($result['type'] ?? '') === 'subscription') {
                    return $this->handleSubscriptionFulfillment($result, $tenantContext, $provisioningService);
                }

                $tenant = $tenantContext->getTenant();

                return redirect()->route('billing.index', ['subdomain' => $tenant->slug])
                    ->with('success', 'Payment successful! Your subscription is being updated.');
            }

            $tenant = $tenantContext->getTenant();

            return redirect()->route('billing.index', ['subdomain' => $tenant->slug])
                ->with('info', 'Payment status: '.$result['status']);

        } catch (Exception $e) {
            Log::error('Payment verification failed: '.$e->getMessage());
            $tenant = $tenantContext->getTenant();

            return redirect()->route('billing.index', ['subdomain' => $tenant->slug])
                ->with('error', 'Unable to verify payment status.');
        }
    }

    private function handlePlanSubscriptionFulfillment(
        array $result,
        TenantContext $tenantContext,
        SubscriptionProvisioningService $provisioningService
    ) {
        $tenant = $tenantContext->getTenant();
        $planSlug = data_get($result, 'metadata.plan_slug');
        $interval = data_get($result, 'metadata.interval', 'month');

        if (! $planSlug) {
            return redirect()->route('billing.index', ['subdomain' => $tenant->slug])
                ->with('error', 'Unable to identify plan from payment metadata.');
        }

        $package = Package::where('slug', $planSlug)->first();

        if (! $package) {
            return redirect()->route('billing.index', ['subdomain' => $tenant->slug])
                ->with('error', 'Plan not found.');
        }

        $dto = [
            'package_id' => $package->id,
            'provider' => 'paystack',
            'provider_subscription_id' => 'ps_'.$result['reference'],
            'provider_plan_id' => $planSlug.'_'.$interval,
            'status' => 'active',
            'current_period_end' => $interval === 'year' ? now()->addYear() : now()->addMonth(),
            'amount_paid' => $result['amount'] ?? 0,
            'currency' => $result['currency'] ?? 'ghs',
            'transaction_id' => $result['reference'],
        ];

        $provisioningService->provision($tenant, $dto);
        $this->sendReceipt($tenant, $result);

        if (! $tenant->onboarding_completed_at) {
            return redirect()->route('tenant.onboarding.wizard', ['subdomain' => $tenant->slug])
                ->with('subscription_activated', $package->name);
        }

        return redirect()->route('billing.index', ['subdomain' => $tenant->slug])
            ->with('success', "Welcome to {$package->name}! Your subscription is now active.");
    }

    private function handleSubscriptionFulfillment(
        array $result,
        TenantContext $tenantContext,
        SubscriptionProvisioningService $provisioningService
    ) {
        $tenant = $tenantContext->getTenant();
        $planId = $result['plan_code'] ?? null;

        if ($planId) {
            $package = Package::where('paystack_plan_code', $planId)
                ->orWhere('paystack_yearly_plan_code', $planId)
                ->first();

            if ($package) {
                $dto = [
                    'package_id' => $package->id,
                    'provider' => 'paystack',
                    'provider_subscription_id' => $result['subscription_code'] ?? $result['reference'],
                    'provider_plan_id' => $planId,
                    'status' => 'active',
                    'current_period_end' => Carbon::now()->addMonth(),
                    'amount_paid' => $result['amount'] ?? 0,
                    'currency' => mb_strtolower($result['currency'] ?? 'usd'),
                    'transaction_id' => $result['reference'],
                ];

                $provisioningService->provision($tenant, $dto);
                $this->sendReceipt($tenant, $result);

                // New tenants go to onboarding; returning customers go to billing
                if (! $tenant->onboarding_completed_at) {
                    return redirect()->route('tenant.onboarding.wizard', ['subdomain' => $tenant->slug])
                        ->with('subscription_activated', $package->name);
                }

                return redirect()->route('billing.index', ['subdomain' => $tenant->slug])
                    ->with('success', "Welcome to {$package->name}! Your subscription is now active.");
            }
        }

        return redirect()->route('billing.index', ['subdomain' => $tenant->slug])
            ->with('success', 'Payment successful! Your subscription is being updated.');
    }

    private function handleInvoiceFulfillment(string $invoiceId, array $result, TenantContext $tenantContext)
    {
        $tenant = $tenantContext->getTenant();
        $invoice = Invoice::where('tenant_id', $tenant->id)->findOrFail($invoiceId);

        app(PaymentFulfillmentService::class)->fulfillInvoice(
            $tenant,
            (string) $invoice->id,
            $result['reference'],
            (int) ($result['amount'] ?? (int) round((float) $invoice->total * 100)),
            mb_strtoupper((string) ($result['currency'] ?? $invoice->currency)),
        );
        $this->sendReceipt($tenant, $result);

        return redirect()->route('billing.invoices.show', $invoice->id)
            ->with('success', 'Invoice paid successfully! Thank you for your payment.');
    }

    private function handleLlmTokenFulfillment(array $result, TenantContext $tenantContext)
    {
        $tenant = $tenantContext->getTenant();
        $packKey = data_get($result, 'metadata.pack_key');
        $packs = Config::get('llm.token_packs', []);

        if (! isset($packs[$packKey])) {
            Log::error("Invalid token pack {$packKey} on fulfillment for tenant {$tenant->id}");

            return redirect()->route('tenant.llm-usage.index', ['subdomain' => $tenant->slug])
                ->with('error', 'Invalid token pack fulfillment.');
        }

        $tokens = (int) $packs[$packKey]['tokens'];

        // Shared with the webhook and keyed on the provider's reference, so a
        // refreshed callback page cannot credit the same pack twice and the
        // webhook cannot credit it again after the browser already did.
        app(PaymentFulfillmentService::class)->fulfillLlmTokenPurchase(
            $tenant,
            $result['reference'],
            (array) data_get($result, 'metadata', []),
        );
        $this->sendReceipt($tenant, $result);

        return redirect()->route('tenant.llm-usage.index', ['subdomain' => $tenant->slug])
            ->with('success', 'Success! '.number_format($tokens).' tokens have been added to your balance.');
    }

    /**
     * The receipt doubles as the "your plan is active" email, and the webhook
     * sends the same one: whichever path runs first sends it, once. A mail
     * failure here is reported, not shown: the payment itself has gone through,
     * and the webhook will still send the receipt.
     *
     * @param  array<string, mixed>  $result
     */
    private function sendReceipt(Tenant $tenant, array $result): void
    {
        rescue(fn () => app(BillingNotifier::class)->paymentReceived($tenant, $result['reference'], auth()->user()?->email, $result));
    }
}
