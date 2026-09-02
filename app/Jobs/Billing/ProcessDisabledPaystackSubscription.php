<?php

declare(strict_types=1);

namespace App\Jobs\Billing;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class ProcessDisabledPaystackSubscription implements ShouldQueue
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
        $event = $this->payload['event'] ?? null;
        $data = $this->payload['data'] ?? [];
        $subscriptionCode = $data['subscription_code'] ?? null;

        \Illuminate\Support\Facades\Log::info("Processing Paystack Subscription Change: {$event}", ['sub_code' => $subscriptionCode]);

        $subscription = \App\Models\Subscription::where('provider_id', $subscriptionCode)->first();

        if (! $subscription) {
            return;
        }

        if ($event === 'subscription.not_renew') {
            // User turned off auto-renew. We keep access active but mark it as such.
            $subscription->update(['provider_status' => 'active']); // Still active until period end

            return;
        }

        if ($event === 'subscription.disable') {
            // Final cessation of service.
            $subscription->update([
                'provider_status' => 'canceled',
                'ends_at' => now(),
            ]);

            $tenant = $subscription->tenant;
            if ($tenant) {
                // Fallback to Free package instead of just clearing all features
                $freePackage = \App\Models\Package::where('slug', 'free')->first();
                if ($freePackage) {
                    $tenant->update(['package_id' => $freePackage->id]);
                    $tenant->syncFeaturesFromPackage();
                } else {
                    // Fail-safe: lockout everything
                    $tenant->features()->update(['enabled' => false]);
                }

                $owner = $tenant->users()->first();
                if ($owner) {
                    \Illuminate\Support\Facades\Mail::to($owner->email)->send(new \App\Mail\Billing\SubscriptionCancelled($tenant));
                }
            }
        }
    }
}
