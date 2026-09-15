<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Checks\RenewalRunCheck;
use App\Services\Operations\OperationalSignals;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;

/**
 * For an external uptime monitor. When the scheduler stops, every scheduled
 * health check stops with it and nothing inside the application can raise the
 * alarm; a monitor polling this URL can. Returns 503 when the scheduler's
 * heartbeat or the daily renewal run is overdue. Reveals only ages.
 */
final class SchedulerHealthController extends Controller
{
    public const int HEARTBEAT_MAX_AGE_MINUTES = 35;

    public function __invoke(OperationalSignals $signals): JsonResponse
    {
        $heartbeat = cache()->get('health:checks:schedule:latestHeartbeatAt');
        $heartbeatAt = $heartbeat ? CarbonImmutable::createFromTimestamp((int) $heartbeat) : null;
        $heartbeatMinutes = $heartbeatAt ? (int) $heartbeatAt->diffInMinutes(now()) : null;

        $renewalRun = $signals->lastRenewalRun();
        $renewalHours = $renewalRun ? (int) $renewalRun['at']->diffInHours(now()) : null;

        $schedulerOk = $heartbeatMinutes !== null && $heartbeatMinutes < self::HEARTBEAT_MAX_AGE_MINUTES;
        $renewalsOk = $renewalHours === null || $renewalHours < RenewalRunCheck::MAX_AGE_HOURS;
        $healthy = $schedulerOk && $renewalsOk;

        return response()->json([
            'status' => $healthy ? 'ok' : 'failing',
            'scheduler_heartbeat_minutes_ago' => $heartbeatMinutes,
            'renewal_run_hours_ago' => $renewalHours,
            'timestamp' => now()->toIso8601String(),
        ], $healthy ? 200 : 503);
    }
}
