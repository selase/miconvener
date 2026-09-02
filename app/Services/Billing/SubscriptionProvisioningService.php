<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\Package;
use App\Models\PlanChange;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\Transaction;
use Illuminate\Support\Facades\Log;

final class SubscriptionProvisioningService
{
    /**
     * Provision a subscription after a successful payment (new or renewal).
     */
    public function provision(Tenant $tenant, array $dto): void
    {
        // 1. Update Tenant Package
        if (isset($dto['package_id'])) {
            $from = $tenant->package_id ? Package::find($tenant->package_id) : null;
            $to = Package::find($dto['package_id']);

            $tenant->update(['package_id' => $dto['package_id']]);
            $tenant->syncFeaturesFromPackage();

            if ($to && optional($from)->id !== $to->id) {
                $this->logPlanChange($tenant, $from, $to, 'upgrade');
            }
        }

        // 2. Create/Update Subscription
        $subscription = Subscription::updateOrCreate(
            [
                'tenant_id' => $tenant->id,
                'provider_id' => $dto['provider_subscription_id'],
            ],
            [
                'name' => 'default',
                'provider_status' => $dto['status'],
                'provider_plan' => $dto['provider_plan_id'],
                'current_period_end' => $dto['current_period_end'],
                'ends_at' => null,
                'pending_package_id' => null,
            ]
        );

        // 3. If a downgrade was pending and has been processed, apply it now
        if ($subscription->wasRecentlyCreated === false && $subscription->hasPendingChange()) {
            $pendingPackage = $subscription->pendingPackage;
            if ($pendingPackage) {
                $this->applyPendingDowngrade($tenant, $subscription, $pendingPackage);
            }
        }

        // 4. Create Transaction record
        Transaction::create([
            'tenant_id' => $tenant->id,
            'provider' => $dto['provider'],
            'provider_transaction_id' => $dto['transaction_id'],
            'amount' => $dto['amount_paid'],
            'currency' => $dto['currency'],
            'status' => 'success',
            'type' => 'charge',
            'meta' => $dto,
        ]);
    }

    /**
     * Immediately upgrade the tenant to a new package.
     * Used when Paystack processes the payment and fires the webhook.
     */
    public function upgrade(Tenant $tenant, Package $newPackage): void
    {
        $from = $tenant->package_id ? Package::find($tenant->package_id) : null;

        $tenant->update(['package_id' => $newPackage->id]);
        $tenant->syncFeaturesFromPackage();

        // Clear any pending downgrade
        $subscription = Subscription::where('tenant_id', $tenant->id)->latest()->first();
        if ($subscription?->hasPendingChange()) {
            $subscription->update(['pending_package_id' => null]);
        }

        $this->logPlanChange($tenant, $from, $newPackage, 'upgrade');

        Log::info("Upgraded tenant {$tenant->id} to package {$newPackage->slug}");
    }

    /**
     * Schedule a downgrade to take effect at the end of the billing period.
     */
    public function downgrade(Tenant $tenant, Package $newPackage): void
    {
        $subscription = Subscription::where('tenant_id', $tenant->id)->latest()->first();

        if ($subscription) {
            $subscription->update(['pending_package_id' => $newPackage->id]);
        }

        $from = $tenant->package_id ? Package::find($tenant->package_id) : null;
        $this->logPlanChange($tenant, $from, $newPackage, 'downgrade', $subscription?->current_period_end);

        Log::info("Scheduled downgrade for tenant {$tenant->id} to package {$newPackage->slug} at period end");
    }

    /**
     * Cancel the subscription at end of period.
     */
    public function cancelAtPeriodEnd(Tenant $tenant): void
    {
        $subscription = Subscription::where('tenant_id', $tenant->id)->latest()->first();

        if ($subscription) {
            $freePackage = Package::where('is_free', true)->first();
            $subscription->update([
                'pending_package_id' => $freePackage?->id,
            ]);
        }

        $from = $tenant->package_id ? Package::find($tenant->package_id) : null;
        $freePackage = Package::where('is_free', true)->first();
        $this->logPlanChange($tenant, $from, $freePackage, 'cancel', $subscription?->current_period_end);

        Log::info("Scheduled cancellation for tenant {$tenant->id} at period end");
    }

    /**
     * Immediately switch tenant to the free plan (called when subscription ends).
     */
    public function switchToFree(Tenant $tenant): void
    {
        $freePackage = Package::where('is_free', true)->first();

        if (! $freePackage) {
            Log::warning("No free package found, cannot switch tenant {$tenant->id} to free");

            return;
        }

        $from = $tenant->package_id ? Package::find($tenant->package_id) : null;

        $tenant->update(['package_id' => $freePackage->id]);
        $tenant->syncFeaturesFromPackage();

        $subscription = Subscription::where('tenant_id', $tenant->id)->latest()->first();
        if ($subscription) {
            $subscription->update([
                'provider_status' => 'cancelled',
                'ends_at' => now(),
                'pending_package_id' => null,
            ]);
        }

        $this->logPlanChange($tenant, $from, $freePackage, 'cancel');

        Log::info("Switched tenant {$tenant->id} to free plan");
    }

    /**
     * Handle subscription renewal — apply any pending downgrade if period has ended.
     */
    private function applyPendingDowngrade(Tenant $tenant, Subscription $subscription, Package $newPackage): void
    {
        $from = $tenant->package_id ? Package::find($tenant->package_id) : null;

        $tenant->update(['package_id' => $newPackage->id]);
        $tenant->syncFeaturesFromPackage();

        $subscription->update(['pending_package_id' => null]);

        $this->logPlanChange($tenant, $from, $newPackage, 'downgrade');
        $this->logPlanChange($tenant, $newPackage, $newPackage, 'processed');

        Log::info("Applied pending downgrade for tenant {$tenant->id} to package {$newPackage->slug}");
    }

    private function logPlanChange(
        Tenant $tenant,
        ?Package $from,
        ?Package $to,
        string $type,
        mixed $effectiveAt = null
    ): void {
        PlanChange::create([
            'tenant_id' => $tenant->id,
            'from_package_id' => $from?->id,
            'to_package_id' => $to?->id,
            'type' => $type,
            'effective_at' => $effectiveAt ?? now(),
            'processed_at' => in_array($type, ['upgrade', 'cancel', 'processed']) ? now() : null,
        ]);
    }
}
