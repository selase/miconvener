<?php

declare(strict_types=1);

namespace App\Http\Controllers\Billing;

use App\Contracts\PaymentGateway;
use App\Http\Controllers\Controller;
use App\Mail\SubscriptionConfirmedMail;
use App\Models\Invoice;
use App\Models\Package;
use App\Models\Transaction;
use App\Services\Billing\SubscriptionProvisioningService;
use App\Services\Billing\WalletService;
use App\Services\Tenancy\TenantContext;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

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

                // Wallet top-up
                if ($type === 'wallet_topup') {
                    return $this->handleWalletTopupFulfillment($result, $tenantContext);
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
            'provider_subscription_id' => 'ps_'.($result['transaction_id'] ?? uniqid()),
            'provider_plan_id' => $planSlug.'_'.$interval,
            'status' => 'active',
            'current_period_end' => $interval === 'year' ? now()->addYear() : now()->addMonth(),
            'amount_paid' => $result['amount'] ?? 0,
            'currency' => $result['currency'] ?? 'ghs',
            'transaction_id' => $result['transaction_id'],
        ];

        $provisioningService->provision($tenant, $dto);

        if (auth()->check()) {
            Mail::to(auth()->user()->email)
                ->queue(new SubscriptionConfirmedMail(auth()->user(), $tenant, $package));
        }

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
                    'provider_subscription_id' => $result['subscription_code'] ?? $result['transaction_id'],
                    'provider_plan_id' => $planId,
                    'status' => 'active',
                    'current_period_end' => Carbon::now()->addMonth(),
                    'amount_paid' => $result['amount'] ?? 0,
                    'currency' => mb_strtolower($result['currency'] ?? 'usd'),
                    'transaction_id' => $result['transaction_id'],
                ];

                $provisioningService->provision($tenant, $dto);

                // Send confirmation email to the authenticated user
                if (auth()->check()) {
                    Mail::to(auth()->user()->email)
                        ->queue(new SubscriptionConfirmedMail(auth()->user(), $tenant, $package));
                }

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

        if ($invoice->status !== Invoice::STATUS_PAID) {
            $invoice->update([
                'status' => Invoice::STATUS_PAID,
                'paid_at' => now(),
            ]);

            Transaction::create([
                'tenant_id' => $tenant->id,
                'invoice_id' => $invoice->id,
                'amount' => $invoice->total * 100,
                'currency' => $invoice->currency,
                'status' => 'success',
                'provider' => config('services.payment.default', 'paystack'),
                'provider_transaction_id' => (string) ($result['transaction_id'] ?? $invoice->number),
            ]);
        }

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

        $pack = $packs[$packKey];
        $tokens = (int) $pack['tokens'];

        DB::connection('landlord')->table('tenants')
            ->where('id', $tenant->id)
            ->increment('llm_topup_balance', $tokens);

        Transaction::create([
            'tenant_id' => $tenant->id,
            'amount' => (int) round((float) $pack['price'] * 100),
            'currency' => $pack['currency'],
            'status' => 'success',
            'type' => 'credit',
            'provider' => config('services.payment.default', 'stripe'),
            'provider_transaction_id' => (string) ($result['transaction_id'] ?? 'token_purchase_'.now()->timestamp),
            'meta' => [
                'type' => 'llm_token_purchase',
                'pack_key' => $packKey,
                'tokens' => $tokens,
            ],
        ]);

        return redirect()->route('tenant.llm-usage.index', ['subdomain' => $tenant->slug])
            ->with('success', 'Success! '.number_format($tokens).' tokens have been added to your balance.');
    }

    private function handleWalletTopupFulfillment(array $result, TenantContext $tenantContext)
    {
        $tenant = $tenantContext->getTenant();
        $packKey = data_get($result, 'metadata.pack_key');
        $packs = Config::get('meeting.wallet.topup_packs', []);

        if (! isset($packs[$packKey])) {
            Log::error("Invalid wallet pack {$packKey} on fulfillment for tenant {$tenant->id}");

            return redirect()->route('tenant.wallet.index', ['subdomain' => $tenant->slug])
                ->with('error', 'Invalid credit pack fulfillment.');
        }

        $pack = $packs[$packKey];
        $credits = (int) $pack['credits'];

        $walletService = app(WalletService::class);
        $walletService->deposit(
            $tenant,
            $credits,
            config('meeting.wallet.currency', 'USD'),
            "Top-up: {$pack['name']} pack ({$credits} credits)",
            ['pack_key' => $packKey],
        );

        Transaction::create([
            'tenant_id' => $tenant->id,
            'amount' => (int) round((float) $pack['price'] * 100),
            'currency' => config('meeting.wallet.currency', 'USD'),
            'status' => 'success',
            'type' => 'credit',
            'provider' => config('services.payment.default', 'paystack'),
            'provider_transaction_id' => (string) ($result['transaction_id'] ?? 'wallet_topup_'.now()->timestamp),
            'meta' => [
                'type' => 'wallet_topup',
                'pack_key' => $packKey,
                'credits' => $credits,
            ],
        ]);

        return redirect()->route('tenant.wallet.index', ['subdomain' => $tenant->slug])
            ->with('success', 'Success! '.number_format($credits).' credits have been added to your wallet.');
    }
}
