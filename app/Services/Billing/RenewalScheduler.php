<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Exceptions\PaymentFailedException;
use App\Models\Package;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Services\Payment\PaystackGateway;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * The daily renewal run.
 *
 * Plans are paid by one-off Paystack charges, so nothing renews or ends them
 * unless this does. For each tenant's current plan subscription:
 *
 *  - before the period ends, a tenant whose payment method can't be charged
 *    automatically is emailed a pay link 7 and 3 days ahead;
 *  - when it ends, a reusable authorization is charged (once a day), and
 *    otherwise the tenant is reminded (once a day) — for up to 7 days' grace;
 *  - when grace is over and nothing was paid, the tenant moves to Free.
 *
 * Every step is safe to run twice on the same day.
 */
final class RenewalScheduler
{
    /** @var list<int> */
    public const array REMINDER_DAYS = [7, 3];

    public function __construct(
        private readonly SubscriptionRenewalService $renewals,
        private readonly SubscriptionProvisioningService $provisioning,
        private readonly BillingNotifier $notifier,
    ) {}

    /**
     * @return list<string> one line per action taken, for the command's output
     */
    public function run(bool $pretend = false): array
    {
        $actions = [];

        foreach ($this->currentSubscriptions() as $subscription) {
            $action = $this->process($subscription, $pretend);

            if ($action !== null) {
                $actions[] = $subscription->tenant->slug.': '.$action;
            }
        }

        return $actions;
    }

    public function process(Subscription $subscription, bool $pretend = false): ?string
    {
        $tenant = $subscription->tenant;
        $periodEnd = $subscription->current_period_end ? CarbonImmutable::parse($subscription->current_period_end) : null;

        if (! $tenant || $tenant->billing_complimentary || ! $periodEnd) {
            return null;
        }

        $currentPackage = Package::query()->find($tenant->package_id);

        if (! $currentPackage || $currentPackage->isFree()) {
            return null;
        }

        $package = $this->renewals->packageToRenew($subscription);
        $now = CarbonImmutable::now();

        if ($package === null) {
            return $now->gte($periodEnd) ? $this->endPlan($tenant, $subscription, $currentPackage, $pretend, 'ended as scheduled') : null;
        }

        $amount = $subscription->priceMinorFor($package);

        if ($now->lt($periodEnd)) {
            return $this->remindBeforeRenewal($tenant, $subscription, $package, $amount, $now, $periodEnd, $pretend);
        }

        $graceEndsAt = $subscription->grace_ends_at
            ? CarbonImmutable::parse($subscription->grace_ends_at)
            : $periodEnd->addDays(SubscriptionRenewalService::GRACE_DAYS);

        if ($now->gte($graceEndsAt)) {
            return $this->endPlan($tenant, $subscription, $currentPackage, $pretend, 'moved to Free after grace');
        }

        if (! $pretend && ! $subscription->isPastDue()) {
            $subscription->update(['provider_status' => Subscription::STATUS_PAST_DUE, 'grace_ends_at' => $graceEndsAt]);
        }

        if ($subscription->canBeChargedAutomatically()) {
            return $this->chargeSavedAuthorization($tenant, $subscription, $package, $amount, $periodEnd, $graceEndsAt, $now, $pretend);
        }

        if (! $pretend) {
            $this->notifier->renewalOverdue($tenant, $subscription, $package, $amount);
        }

        return 'overdue reminder, grace ends '.$graceEndsAt->toDateString();
    }

    /**
     * @return iterable<Subscription>
     */
    private function currentSubscriptions(): iterable
    {
        return Subscription::query()
            ->with('tenant')
            ->whereIn('provider_status', [Subscription::STATUS_ACTIVE, Subscription::STATUS_PAST_DUE])
            ->where('provider_id', 'like', 'ps\_%')
            ->whereIn('id', Subscription::query()->selectRaw('max(id)')->where('provider_id', 'like', 'ps\_%')->groupBy('tenant_id'))
            ->orderBy('id')
            ->lazyById();
    }

    private function remindBeforeRenewal(Tenant $tenant, Subscription $subscription, Package $package, int $amount, CarbonImmutable $now, CarbonImmutable $periodEnd, bool $pretend): ?string
    {
        if ($subscription->canBeChargedAutomatically()) {
            return null;
        }

        $daysLeft = (int) $now->startOfDay()->diffInDays($periodEnd->startOfDay(), false);
        $threshold = collect(self::REMINDER_DAYS)->filter(fn (int $days): bool => $daysLeft <= $days)->min();

        if ($threshold === null) {
            return null;
        }

        if (! $pretend) {
            $this->notifier->renewalDue($tenant, $subscription, $package, $amount, $threshold);
        }

        return "renewal reminder ({$threshold}-day), due {$periodEnd->toDateString()}";
    }

    private function chargeSavedAuthorization(Tenant $tenant, Subscription $subscription, Package $package, int $amount, CarbonImmutable $periodEnd, CarbonImmutable $graceEndsAt, CarbonImmutable $now, bool $pretend): ?string
    {
        if ($subscription->renewal_attempted_at && CarbonImmutable::parse($subscription->renewal_attempted_at)->isSameDay($now)) {
            return null;
        }

        $attempt = $subscription->renewal_attempts + 1;
        $reference = "renew-{$subscription->id}-{$periodEnd->format('Ymd')}-{$attempt}";
        $display = BillingNotifier::money($amount, (string) config('services.paystack.currency', 'GHS'));

        if ($pretend) {
            return "charge {$display} to {$subscription->authorization_label} (attempt {$attempt})";
        }

        // Recorded before charging, so a crash after Paystack answers cannot
        // lead to a second charge the same day.
        $subscription->update(['renewal_attempts' => $attempt, 'renewal_attempted_at' => $now]);

        try {
            $result = app(PaystackGateway::class)->chargeAuthorization(
                (string) $subscription->authorization_email,
                $amount,
                (string) config('services.paystack.currency', 'GHS'),
                (string) $subscription->authorization_code,
                $reference,
                [
                    'source' => config('services.paystack.metadata_source'),
                    'type' => 'plan_renewal',
                    'tenant_id' => $tenant->id,
                    'subscription_id' => $subscription->id,
                    'package_id' => $package->id,
                ],
            );
        } catch (PaymentFailedException $e) {
            // Paystack refused the request itself, e.g. an authorization that is
            // no longer valid. Retrying daily can't help; ask for a pay link.
            Log::warning("Renewal charge rejected for subscription {$subscription->id}", ['error' => $e->getMessage()]);
            $subscription->update(['authorization_reusable' => false]);
            $this->notifier->renewalOverdue($tenant, $subscription->fresh() ?? $subscription, $package, $amount);

            return 'saved payment method rejected; overdue reminder sent';
        }

        if ($result['status'] === 'success') {
            $this->renewals->recordRenewal($subscription, $package, $result['reference'], $result['amount'], $result['currency'], $result + ['customer_email' => $subscription->authorization_email]);
            $this->notifier->paymentReceived($tenant, $result['reference'], $subscription->authorization_email, $result);

            return "charged {$display} (attempt {$attempt})";
        }

        if ($result['status'] === 'failed') {
            $retryOn = $now->addDay()->lt($graceEndsAt) ? $now->addDay()->toDateString() : null;
            $this->notifier->renewalChargeFailed($tenant, $subscription->fresh() ?? $subscription, $amount, $reference, $retryOn);

            return "charge declined ({$result['gateway_response']}), attempt {$attempt}";
        }

        return "charge {$result['status']}, waiting for Paystack (attempt {$attempt})";
    }

    private function endPlan(Tenant $tenant, Subscription $subscription, Package $currentPackage, bool $pretend, string $why): string
    {
        if (! $pretend) {
            $this->provisioning->switchToFree($tenant);
            $this->notifier->planLapsed($tenant, $subscription->fresh() ?? $subscription, $currentPackage->name);
        }

        return "{$why}: {$currentPackage->name} → Free";
    }
}
