<?php

declare(strict_types=1);

namespace App\Services\Operations;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * The few facts health checks and the daily billing summary need about work
 * that happens out of sight: when the renewal run last ran and what it did, and
 * Paystack webhooks that were refused or failed.
 *
 * Kept in the cache store, which the CacheCheck already watches: these are
 * alerting signals, not records, and losing them loses nothing but an alert.
 */
final class OperationalSignals
{
    private const string RENEWAL_RUN_KEY = 'ops:renewals:last_run';

    private const string WEBHOOK_FAILURES_KEY = 'ops:paystack:webhook_failures';

    /**
     * @param  list<string>  $actions
     */
    public function recordRenewalRun(array $actions): void
    {
        Cache::forever(self::RENEWAL_RUN_KEY, [
            'at' => now()->toIso8601String(),
            'actions' => $actions,
        ]);
    }

    /**
     * @return array{at: CarbonImmutable, actions: list<string>}|null
     */
    public function lastRenewalRun(): ?array
    {
        $run = Cache::get(self::RENEWAL_RUN_KEY);

        if (! is_array($run) || ! isset($run['at'])) {
            return null;
        }

        return ['at' => CarbonImmutable::parse($run['at']), 'actions' => array_values((array) ($run['actions'] ?? []))];
    }

    /**
     * @param  string  $kind  "signature" for a request we refused, "processing" for one we failed to handle
     */
    public function recordWebhookFailure(string $kind, string $detail): void
    {
        $failures = collect($this->webhookFailures())
            ->filter(fn (array $failure): bool => CarbonImmutable::parse($failure['at'])->gt(now()->subDay()))
            ->push(['at' => now()->toIso8601String(), 'kind' => $kind, 'detail' => mb_substr($detail, 0, 200)])
            ->take(-100)
            ->values()
            ->all();

        Cache::forever(self::WEBHOOK_FAILURES_KEY, $failures);
    }

    /**
     * @return list<array{at: string, kind: string, detail: string}>
     */
    public function webhookFailuresSince(CarbonImmutable $since, ?string $kind = null): array
    {
        return collect($this->webhookFailures())
            ->filter(fn (array $failure): bool => CarbonImmutable::parse($failure['at'])->gte($since)
                && ($kind === null || $failure['kind'] === $kind))
            ->values()
            ->all();
    }

    /**
     * @return list<array{at: string, kind: string, detail: string}>
     */
    private function webhookFailures(): array
    {
        $failures = Cache::get(self::WEBHOOK_FAILURES_KEY, []);

        return is_array($failures) ? array_values($failures) : [];
    }
}
