<?php

declare(strict_types=1);

namespace App\Jobs\Billing;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class ProcessFailedPaystackCharge implements ShouldQueue
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

        \Illuminate\Support\Facades\Log::warning('Paystack Payment Failed', ['customer' => $customerCode]);

        $tenant = \App\Models\Tenant::where('meta->paystack_customer_code', $customerCode)->first();

        if ($tenant && $tenant->latestSubscription) {
            $owner = $tenant->users()->first();
            if ($owner) {
                // Send dunning email. Paystack will retry automatically if configured.
                \Illuminate\Support\Facades\Mail::to($owner->email)->send(new \App\Mail\Billing\PaymentFailedDunning($tenant));
            }
        }
    }
}
