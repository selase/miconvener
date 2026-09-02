<?php

declare(strict_types=1);

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Models\Package;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Services\Billing\SubscriptionProvisioningService;
use App\Services\Billing\WalletService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use UnexpectedValueException;

final class WebhookController extends Controller
{
    public function handleStripe(Request $request, SubscriptionProvisioningService $provisioningService)
    {
        $payload = $request->getContent();
        $sigHeader = $request->header('Stripe-Signature');
        $endpointSecret = config('services.stripe.webhook_secret');

        try {
            $event = \Stripe\Webhook::constructEvent(
                $payload,
                $sigHeader,
                $endpointSecret
            );
        } catch (UnexpectedValueException) {
            return response()->json(['error' => 'Invalid payload'], 400);
        } catch (\Stripe\Exception\SignatureVerificationException) {
            return response()->json(['error' => 'Invalid signature'], 400);
        }

        switch ($event->type) {
            case 'invoice.payment_succeeded':
                $stripeInvoice = $event->data->object;
                $customerId = $stripeInvoice->customer;
                $tenant = Tenant::where('meta->stripe_id', $customerId)->first();

                if ($tenant) {
                    $dto = [
                        'tenant_id' => $tenant->id,
                        'provider' => 'stripe',
                        'provider_subscription_id' => $stripeInvoice->subscription,
                        'provider_plan_id' => $stripeInvoice->lines->data[0]->price->id ?? null,
                        'status' => 'active',
                        'current_period_end' => isset($stripeInvoice->lines->data[0]->period->end)
                            ? Carbon::createFromTimestamp($stripeInvoice->lines->data[0]->period->end)
                            : now()->addMonth(),
                        'amount_paid' => $stripeInvoice->amount_paid,
                        'currency' => $stripeInvoice->currency,
                        'transaction_id' => $stripeInvoice->payment_intent ?? $stripeInvoice->id,
                    ];

                    $provisioningService->provision($tenant, $dto);
                    Log::info("Provisioned subscription for tenant {$tenant->id}");
                } else {
                    Log::warning("Tenant not found for Stripe Customer {$customerId}");
                }
                break;

            case 'checkout.session.completed':
                $session = $event->data->object;

                if (isset($session->metadata->invoice_id)) {
                    $invoice = \App\Models\Invoice::find($session->metadata->invoice_id);
                    if ($invoice && $invoice->status !== \App\Models\Invoice::STATUS_PAID) {
                        $invoice->update([
                            'status' => \App\Models\Invoice::STATUS_PAID,
                            'paid_at' => now(),
                        ]);

                        Transaction::create([
                            'tenant_id' => $invoice->tenant_id,
                            'invoice_id' => $invoice->id,
                            'amount' => $invoice->total * 100,
                            'currency' => $invoice->currency,
                            'status' => 'success',
                            'provider' => 'stripe',
                            'provider_transaction_id' => $session->payment_intent,
                        ]);
                        Log::info("Filled invoice {$invoice->id} via Stripe Webhook");
                    }
                }
                break;

            case 'customer.subscription.deleted':
                // Handle cancellation
                break;

            default:
                Log::info('Received unknown Stripe event type '.$event->type);
        }

        return response()->json(['status' => 'success']);
    }

    public function handlePaystack(Request $request, SubscriptionProvisioningService $provisioningService)
    {
        // Verify SHA512 HMAC signature
        $signature = $request->header('X-Paystack-Signature');
        $secret = config('services.paystack.secret_key', '');
        $expected = hash_hmac('sha512', $request->getContent(), (string) $secret);

        if (! hash_equals($expected, (string) $signature)) {
            Log::warning('Paystack webhook signature mismatch');

            return response()->json(['error' => 'Invalid signature'], 400);
        }

        $payload = $request->json()->all();
        $event = $payload['event'] ?? '';
        $data = $payload['data'] ?? [];

        Log::info("Paystack webhook received: {$event}");

        match ($event) {
            'charge.success' => $this->handlePaystackChargeSuccess($data, $provisioningService),
            'subscription.create' => $this->handlePaystackSubscriptionCreate($data),
            'subscription.not_renew' => $this->handlePaystackSubscriptionNotRenew($data, $provisioningService),
            'subscription.disable' => $this->handlePaystackSubscriptionDisable($data, $provisioningService),
            'invoice.payment_failed' => $this->handlePaystackInvoiceFailed($data),
            default => Log::info("Unhandled Paystack event: {$event}"),
        };

        return response()->json(['status' => 'success']);
    }

    private function handlePaystackChargeSuccess(array $data, SubscriptionProvisioningService $provisioningService): void
    {
        $customerEmail = $data['customer']['email'] ?? null;
        $metadata = $data['metadata'] ?? [];
        $planCode = $data['plan']['plan_code'] ?? null;

        if (! $customerEmail) {
            return;
        }

        // Find tenant by Paystack customer code stored in meta
        $customerCode = $data['customer']['customer_code'] ?? null;
        $tenant = $customerCode
            ? Tenant::whereJsonContains('meta->paystack_id', $customerCode)->first()
            : null;

        // Fallback: find tenant whose owner email matches
        if (! $tenant) {
            $tenant = Tenant::whereHas('users', fn ($q) => $q->where('email', $customerEmail))->first();
        }

        if (! $tenant) {
            Log::warning("Paystack charge.success: no tenant found for email {$customerEmail}");

            return;
        }

        // Metadata-driven wallet top-up
        $metaType = $metadata['type'] ?? null;
        if ($metaType === 'wallet_topup') {
            $packKey = $metadata['pack_key'] ?? null;
            $packs = Config::get('meeting.wallet.topup_packs', []);

            if ($packKey && isset($packs[$packKey])) {
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
                    'provider' => 'paystack',
                    'provider_transaction_id' => (string) ($data['reference'] ?? $data['id']),
                    'meta' => [
                        'type' => 'wallet_topup',
                        'pack_key' => $packKey,
                        'credits' => $credits,
                    ],
                ]);

                Log::info("Paystack: wallet top-up {$credits} credits for tenant {$tenant->id}");
            }

            return;
        }

        // Metadata-driven plan subscription (no Paystack plan codes required)
        if ($metaType === 'plan_subscription') {
            $planSlug = $metadata['plan_slug'] ?? null;
            $interval = $metadata['interval'] ?? 'month';

            if ($planSlug) {
                $package = Package::where('slug', $planSlug)->first();

                if ($package) {
                    $dto = [
                        'package_id' => $package->id,
                        'provider' => 'paystack',
                        'provider_subscription_id' => 'ps_'.($data['reference'] ?? $data['id']),
                        'provider_plan_id' => $planSlug.'_'.$interval,
                        'status' => 'active',
                        'current_period_end' => $interval === 'year' ? now()->addYear() : now()->addMonth(),
                        'amount_paid' => $data['amount'] ?? 0,
                        'currency' => mb_strtolower($data['currency'] ?? 'ghs'),
                        'transaction_id' => $data['reference'] ?? $data['id'],
                    ];

                    $provisioningService->provision($tenant, $dto);
                    Log::info("Paystack: provisioned {$planSlug}/{$interval} for tenant {$tenant->id}");
                }
            }

            return;
        }

        // Legacy: plan code approach (Paystack subscription plans)
        if ($planCode) {
            $package = Package::where('paystack_plan_code', $planCode)
                ->orWhere('paystack_yearly_plan_code', $planCode)
                ->first();

            if ($package) {
                $subscriptionCode = $data['subscription_code'] ?? ($data['plan_object']['subscription_code'] ?? null);
                $nextPaymentDate = $data['paid_at'] ? Carbon::parse($data['paid_at'])->addMonth() : now()->addMonth();

                $dto = [
                    'package_id' => $package->id,
                    'provider' => 'paystack',
                    'provider_subscription_id' => $subscriptionCode ?? $data['id'],
                    'provider_plan_id' => $planCode,
                    'status' => 'active',
                    'current_period_end' => $nextPaymentDate,
                    'amount_paid' => $data['amount'] ?? 0,
                    'currency' => mb_strtolower($data['currency'] ?? 'usd'),
                    'transaction_id' => $data['reference'] ?? $data['id'],
                ];

                $provisioningService->provision($tenant, $dto);
                Log::info("Paystack: provisioned subscription for tenant {$tenant->id} → package {$package->slug}");
            }
        }
    }

    private function handlePaystackSubscriptionCreate(array $data): void
    {
        // Subscription record creation — provisioning already handled by charge.success
        Log::info('Paystack subscription created: '.($data['subscription_code'] ?? 'unknown'));
    }

    private function handlePaystackSubscriptionNotRenew(array $data, SubscriptionProvisioningService $provisioningService): void
    {
        // Subscription will not renew — apply any pending downgrade or switch to free
        $customerEmail = $data['customer']['email'] ?? null;
        if (! $customerEmail) {
            return;
        }

        $tenant = Tenant::whereHas('users', fn ($q) => $q->where('email', $customerEmail))->first();
        if (! $tenant) {
            return;
        }

        // If there's a pending downgrade, it's already set; otherwise schedule free
        $subscription = \App\Models\Subscription::where('tenant_id', $tenant->id)->latest()->first();
        if ($subscription && ! $subscription->hasPendingChange()) {
            $provisioningService->cancelAtPeriodEnd($tenant);
        }

        Log::info("Paystack subscription.not_renew: scheduled end for tenant {$tenant->id}");
    }

    private function handlePaystackSubscriptionDisable(array $data, SubscriptionProvisioningService $provisioningService): void
    {
        // Subscription has ended — switch to free
        $customerEmail = $data['customer']['email'] ?? null;
        if (! $customerEmail) {
            return;
        }

        $tenant = Tenant::whereHas('users', fn ($q) => $q->where('email', $customerEmail))->first();
        if (! $tenant) {
            return;
        }

        $provisioningService->switchToFree($tenant);
        Log::info("Paystack subscription.disable: switched tenant {$tenant->id} to free");
    }

    private function handlePaystackInvoiceFailed(array $data): void
    {
        $customerEmail = $data['customer']['email'] ?? null;
        Log::warning("Paystack invoice.payment_failed for customer {$customerEmail}");

        // Could send a payment failure notification here
    }
}
