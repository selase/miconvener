<?php

declare(strict_types=1);

namespace App\Checks;

use App\Services\Operations\OperationalSignals;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;

/**
 * Plans are renewed, reminded and ended only by the daily run. If it stops,
 * nothing fails loudly: plans simply never renew or lapse.
 */
final class RenewalRunCheck extends Check
{
    public const int MAX_AGE_HOURS = 26;

    public function run(): Result
    {
        $run = app(OperationalSignals::class)->lastRenewalRun();

        if ($run === null) {
            return Result::make()
                ->warning('No plan renewal run has been recorded yet. The first runs at 07:00.')
                ->shortSummary('Not run yet');
        }

        $hours = (int) $run['at']->diffInHours(now());
        $result = Result::make()->meta(['last_run_at' => $run['at']->toIso8601String(), 'actions' => count($run['actions'])]);

        if ($hours >= self::MAX_AGE_HOURS) {
            return $result
                ->failed("The daily plan renewal run last ran {$hours} hours ago. Plans are not being renewed, reminded or ended.")
                ->shortSummary("{$hours}h ago");
        }

        return $result->ok()->shortSummary($run['at']->diffForHumans());
    }
}
