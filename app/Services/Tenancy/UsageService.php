<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

use App\Enum\UsageMetric;
use App\Models\Tenant;
use App\Models\UsageEvent;
use App\Models\UsageRollup;

final class UsageService implements \App\Contracts\UsageServiceContract
{
    /**
     * Record an HTTP request.
     */
    public function recordRequest(Tenant $tenant, string $route, int $status, string $statusBucket, int $durationMs): void
    {
        $now = now();
        $this->recordEvent($tenant, UsageMetric::REQUEST_COUNT, $route, 1, 'count', [
            'status' => $status,
            'status_bucket' => $statusBucket,
        ]);

        $this->recordEvent($tenant, UsageMetric::REQUEST_DURATION_MS, $route, $durationMs, 'ms', [
            'status' => $status,
            'status_bucket' => $statusBucket,
        ]);

        // Update Minute Rollups (Real-time charts)
        $this->updateRollup($tenant, 'minute', $now, UsageMetric::REQUEST_COUNT, 1, [
            'route' => $route,
            'status_bucket' => $statusBucket,
        ]);

        $this->updateRollup($tenant, 'minute', $now, UsageMetric::REQUEST_DURATION_MS, $durationMs, [
            'route' => $route,
            'status_bucket' => $statusBucket,
        ]);
    }

    /**
     * Record a Queue Job.
     */
    public function recordJob(Tenant $tenant, string $jobClass, bool $success, int $runtimeMs): void
    {
        $now = now();
        $metric = $success ? UsageMetric::JOB_COUNT : UsageMetric::JOB_FAILED_COUNT;

        $this->recordEvent($tenant, $metric, $jobClass, 1, 'count');
        $this->recordEvent($tenant, UsageMetric::JOB_RUNTIME_MS, $jobClass, $runtimeMs, 'ms');

        $this->updateRollup($tenant, 'minute', $now, $metric, 1, ['job_class' => $jobClass]);
        $this->updateRollup($tenant, 'minute', $now, UsageMetric::JOB_RUNTIME_MS, $runtimeMs, ['job_class' => $jobClass]);
    }

    /**
     * Record an active user for the day.
     */
    public function recordActiveUser(Tenant $tenant, string $userId): void
    {
        \Illuminate\Support\Facades\Log::info('UsageService::recordActiveUser', ['tenant' => $tenant->id, 'user' => $userId]);
        $now = now();
        $this->recordEvent($tenant, UsageMetric::USER_ACTIVE_DAILY, $userId, 1, 'count');

        // Update Daily Rollup
        $this->updateRollup($tenant, 'day', $now, UsageMetric::USER_ACTIVE_DAILY, 1);
    }

    /**
     * Internal: Update or create a rollup record.
     */
    public function updateRollup(Tenant $tenant, string $period, \Carbon\CarbonInterface $time, UsageMetric $metric, $value, array $dimensions = []): void
    {
        $periodStart = match ($period) {
            'minute' => $time->copy()->second(0)->microsecond(0),
            'hour' => $time->copy()->minute(0)->second(0)->microsecond(0),
            'day' => $time->copy()->startOfDay(),
            default => $time,
        };

        $hash = UsageRollup::hashDimensions($dimensions);

        /** @var UsageRollup $rollup */
        $rollup = UsageRollup::firstOrCreate(
            [
                'tenant_id' => $tenant->id,
                'period' => $period,
                'period_start' => $periodStart,
                'metric' => $metric,
                'dimensions_hash' => $hash,
            ],
            [
                'dimensions' => $dimensions,
                'value' => 0,
            ]
        );

        // Perform atomic increment
        UsageRollup::where('id', $rollup->id)->increment('value', $value);
    }

    /**
     * Internal: Record a raw event.
     */
    private function recordEvent(Tenant $tenant, UsageMetric $type, ?string $key, int $quantity, string $unit, array $meta = []): void
    {
        UsageEvent::create([
            'tenant_id' => $tenant->id,
            'occurred_at' => now(),
            'type' => $type,
            'key' => $key,
            'quantity' => $quantity,
            'unit' => $unit,
            'meta' => $meta,
        ]);
    }
}
