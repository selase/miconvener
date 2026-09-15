<?php

declare(strict_types=1);

namespace App\Checks;

use Illuminate\Support\Facades\DB;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;

/**
 * Receipts, renewal reminders and ticket confirmations are queued. A job that
 * fails is otherwise only visible in the failed_jobs table.
 *
 * Only recent failures fail the check, so one bad job raises one alert rather
 * than an email every hour for a day; the daily summary counts the rest.
 */
final class FailedJobsCheck extends Check
{
    public const int WINDOW_MINUTES = 30;

    public function run(): Result
    {
        $recent = DB::connection('landlord')->table('failed_jobs')
            ->where('failed_at', '>=', now()->subMinutes(self::WINDOW_MINUTES))
            ->orderByDesc('failed_at')
            ->get(['payload', 'exception']);

        if ($recent->isEmpty()) {
            return Result::make()->ok()->shortSummary('None');
        }

        $latest = $recent->first();
        $job = (string) (json_decode((string) $latest->payload, true)['displayName'] ?? 'a job');
        $reason = mb_substr(mb_trim(strtok((string) $latest->exception, "\n") ?: ''), 0, 160);

        return Result::make()
            ->failed("{$recent->count()} queued job(s) failed in the last ".self::WINDOW_MINUTES." minutes. Latest: {$job} — {$reason}")
            ->shortSummary((string) $recent->count());
    }
}
