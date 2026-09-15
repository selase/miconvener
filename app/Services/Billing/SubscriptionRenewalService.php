<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\Package;
use App\Models\Subscription;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Records a paid renewal: from a saved card charged by the daily run, a pay
 * link, or the webhook reporting either. All of them call recordRenewal() with
 * the Paystack reference, and only the first changes anything.
 */
final class SubscriptionRenewalService
{
    public const int GRACE_DAYS = 7;

    public function __construct(private readonly SubscriptionProvisioningService $provisioning) {}

    /**
     * Extend the subscription by one period on the given package.
     *
     * The new period starts where the old one ended, so paying early loses no
     * days and paying during grace gains none. A plan that already lapsed to
     * Free starts a fresh period today.
     *
     * @param  array<string, mixed>  $provider  Paystack's transaction data.
     */
    public function recordRenewal(Subscription $subscription, Package $package, string $reference, int $amountMinor, string $currency, array $provider = []): bool
    {
        try {
            return DB::connection('landlord')->transaction(function () use ($subscription, $package, $reference, $amountMinor, $currency, $provider): bool {
                $subscription = Subscription::query()->whereKey($subscription->getKey())->lockForUpdate()->firstOrFail();

                if (Transaction::query()->where('provider_transaction_id', $reference)->exists()) {
                    return false;
                }

                $tenant = $subscription->tenant()->firstOrFail();
                $lapsed = $subscription->provider_status === Subscription::STATUS_CANCELLED
                    || $subscription->current_period_end === null
                    || $subscription->current_period_end->lt(now()->subDays(self::GRACE_DAYS));

                $start = $lapsed ? CarbonImmutable::now() : CarbonImmutable::parse($subscription->current_period_end);
                $interval = $subscription->billingInterval();
                $periodEnd = $interval === 'year' ? $start->addYear() : $start->addMonth();

                $this->provisioning->moveToPackage($tenant, $package, $lapsed ? 'reactivate' : 'downgrade');

                $subscription->update([
                    'provider_status' => Subscription::STATUS_ACTIVE,
                    'provider_plan' => $package->slug.'_'.$interval,
                    'current_period_end' => $periodEnd,
                    'ends_at' => null,
                    'grace_ends_at' => null,
                    'renewal_attempts' => 0,
                    'pending_package_id' => null,
                    ...$this->refreshedAuthorization($subscription, $provider),
                ]);

                Transaction::query()->create([
                    'tenant_id' => $tenant->id,
                    'provider' => 'paystack',
                    'provider_transaction_id' => $reference,
                    'amount' => $amountMinor,
                    'currency' => mb_strtolower($currency),
                    'status' => 'success',
                    'type' => 'charge',
                    'meta' => [
                        'type' => 'plan_renewal',
                        'subscription_id' => $subscription->id,
                        'package_id' => $package->id,
                        'provider_plan_id' => $package->slug.'_'.$interval,
                        'current_period_end' => $periodEnd->toIso8601String(),
                    ],
                ]);

                return true;
            });
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }

    /**
     * The package a renewal of this subscription is charged for: a scheduled
     * downgrade to another paid plan takes effect at renewal; otherwise the
     * tenant's current plan. Null when there is nothing to charge for.
     */
    public function packageToRenew(Subscription $subscription): ?Package
    {
        $pending = $subscription->pending_package_id ? Package::query()->find($subscription->pending_package_id) : null;

        if ($pending && ! $pending->isFree()) {
            return $pending;
        }

        $current = Package::query()->find($subscription->tenant()->value('package_id'));

        return $current && ! $current->isFree() ? $current : null;
    }

    /**
     * A payment made with a reusable method replaces the stored one, so a
     * mobile money payer who renews by card is charged automatically next time.
     *
     * @param  array<string, mixed>  $provider
     * @return array<string, mixed>
     */
    private function refreshedAuthorization(Subscription $subscription, array $provider): array
    {
        $authorization = (array) ($provider['authorization'] ?? []);

        if (! ($authorization['reusable'] ?? false)) {
            return [];
        }

        return SubscriptionProvisioningService::authorizationAttributes(
            $authorization,
            $provider['customer']['email'] ?? $provider['customer_email'] ?? $subscription->authorization_email,
        );
    }
}
