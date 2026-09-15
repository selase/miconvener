<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Checks\RenewalRunCheck;
use App\Models\Package;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Services\Operations\OperationalSignals;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * What the platform's owners should know about billing each morning: what the
 * renewal run did, who is overdue, what renews this week, what was paid, and
 * anything that failed.
 */
final class BillingDailySummary
{
    public function __construct(private readonly OperationalSignals $signals) {}

    /**
     * @return array{
     *     renewal_run: array{at: ?string, stale: bool, actions: list<string>},
     *     past_due: list<array{tenant: string, plan: string, grace_ends: ?string, method: string}>,
     *     renewing_soon: list<array{tenant: string, plan: string, on: string, amount: string, method: string}>,
     *     payments: array{count: int, totals: list<string>},
     *     failed_jobs: int,
     *     webhook_failures: array{processing: int, signature: int}
     * }
     */
    public function build(): array
    {
        $run = $this->signals->lastRenewalRun();
        $since = CarbonImmutable::now()->subDay();

        return [
            'renewal_run' => [
                'at' => $run ? BillingNotifier::date($run['at']).' '.$run['at']->format('H:i') : null,
                'stale' => $run !== null && $run['at']->diffInHours(now()) >= RenewalRunCheck::MAX_AGE_HOURS,
                'actions' => $run && $run['at']->gte($since) ? $run['actions'] : [],
            ],
            'past_due' => $this->currentSubscriptions()
                ->where('provider_status', Subscription::STATUS_PAST_DUE)
                ->map(fn (Subscription $subscription): array => [
                    'tenant' => $subscription->tenant->name,
                    'plan' => (string) Package::query()->whereKey($subscription->tenant->package_id)->value('name'),
                    'grace_ends' => $subscription->grace_ends_at ? BillingNotifier::date($subscription->grace_ends_at) : null,
                    'method' => $subscription->canBeChargedAutomatically() ? (string) $subscription->authorization_label : 'Payment link',
                ])
                ->values()
                ->all(),
            'renewing_soon' => $this->currentSubscriptions()
                ->where('provider_status', Subscription::STATUS_ACTIVE)
                ->filter(fn (Subscription $subscription): bool => $subscription->current_period_end !== null
                    && $subscription->current_period_end->between(now(), now()->addDays(7)))
                ->map(function (Subscription $subscription): ?array {
                    $package = app(SubscriptionRenewalService::class)->packageToRenew($subscription);

                    return $package ? [
                        'tenant' => $subscription->tenant->name,
                        'plan' => $package->name,
                        'on' => BillingNotifier::date($subscription->current_period_end),
                        'amount' => BillingNotifier::money($subscription->priceMinorFor($package), (string) config('services.paystack.currency', 'GHS')),
                        'method' => $subscription->canBeChargedAutomatically() ? (string) $subscription->authorization_label : 'Payment link',
                    ] : null;
                })
                ->filter()
                ->values()
                ->all(),
            'payments' => [
                'count' => Transaction::query()->where('status', 'success')->where('created_at', '>=', $since)->count(),
                'totals' => DB::connection('landlord')->table('transactions')
                    ->where('status', 'success')
                    ->where('created_at', '>=', $since)
                    ->selectRaw('lower(currency) as currency, sum(amount) as total')
                    ->groupByRaw('lower(currency)')
                    ->get()
                    ->map(fn (object $row): string => BillingNotifier::money((int) $row->total, (string) $row->currency))
                    ->all(),
            ],
            'failed_jobs' => DB::connection('landlord')->table('failed_jobs')->where('failed_at', '>=', $since)->count(),
            'webhook_failures' => [
                'processing' => count($this->signals->webhookFailuresSince($since, 'processing')),
                'signature' => count($this->signals->webhookFailuresSince($since, 'signature')),
            ],
        ];
    }

    /**
     * Worth an email: something happened, or something needs attention.
     *
     * @param  array<string, mixed>  $summary
     */
    public function isNoteworthy(array $summary): bool
    {
        return $summary['renewal_run']['stale']
            || $summary['renewal_run']['actions'] !== []
            || $summary['past_due'] !== []
            || $summary['renewing_soon'] !== []
            || $summary['payments']['count'] > 0
            || $summary['failed_jobs'] > 0
            || $summary['webhook_failures']['processing'] > 0
            || $summary['webhook_failures']['signature'] > 0;
    }

    /**
     * @return \Illuminate\Support\Collection<int, Subscription>
     */
    private function currentSubscriptions(): \Illuminate\Support\Collection
    {
        return Subscription::query()
            ->with('tenant')
            ->where('provider_id', 'like', 'ps\_%')
            ->whereIn('id', Subscription::query()->selectRaw('max(id)')->where('provider_id', 'like', 'ps\_%')->groupBy('tenant_id'))
            ->whereHas('tenant', fn ($query) => $query->where('billing_complimentary', false))
            ->get()
            ->filter(fn (Subscription $subscription): bool => $subscription->tenant instanceof Tenant);
    }
}
