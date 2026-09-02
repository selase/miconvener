<?php

declare(strict_types=1);

namespace App\Jobs\Billing;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class ProcessSuccessfulPaystackCharge implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(public array $payload)
    {
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $data = $this->payload['data'] ?? [];

        $customerCode = $data['customer']['customer_code'] ?? null;
        $reference = $data['reference'] ?? null;

        if (! $reference || ! \Illuminate\Support\Facades\Cache::add("paystack_tx_{$reference}", true, now()->addDays(30))) {
            \Illuminate\Support\Facades\Log::info('Paystack Webhook Ignored (Duplicate/Missing Ref)', ['ref' => $reference]);

            return;
        }

        \Illuminate\Support\Facades\Log::info('Processing Paystack Charge Success', ['customer' => $customerCode, 'ref' => $reference]);

        // In Paystack, charge.success for a subscription usually includes the plan.
        // It might not directly include subscription_code in all generic charge events,
        // so we attempt to locate it or fallback.
        $customerCode = $data['customer']['customer_code'] ?? null;

        \Illuminate\Support\Facades\Log::info('Processing Paystack Charge Success', ['customer' => $customerCode]);

        $tenant = \App\Models\Tenant::where('meta->paystack_customer_code', $customerCode)->first();

        if ($tenant) {
            // 1. Try to sync package if plan_code is provided
            $planCode = $data['plan']['plan_code'] ?? null;
            if ($planCode) {
                $package = \App\Models\Package::where('paystack_plan_code', $planCode)->first();
                if ($package) {
                    $tenant->update(['package_id' => $package->id]);
                    $tenant->syncFeaturesFromPackage();
                }
            }

            // 2. Ensure subscription record exists and is active
            $subCode = $data['subscription']['subscription_code'] ?? null;
            $subscription = \App\Models\Subscription::updateOrCreate(
                ['tenant_id' => $tenant->id, 'name' => 'default'],
                [
                    'provider_id' => $subCode ?? ($tenant->latestSubscription->provider_id ?? 'SUB_GEN_'.str()->random(8)),
                    'provider_status' => 'active',
                    'ends_at' => now()->addMonth(),
                ]
            );

            // 3. Top up metered usage
            $tokens = config('billing.monthly_llm_tokens', 100000);
            $tenant->update(['llm_topup_balance' => $tokens]);

            // 4. Send receipt
            $owner = $tenant->users()->first();
            if ($owner) {
                \Illuminate\Support\Facades\Mail::to($owner->email)->send(new \App\Mail\Billing\PaymentSuccessReceipt($tenant));
            }
        }
    }
}
