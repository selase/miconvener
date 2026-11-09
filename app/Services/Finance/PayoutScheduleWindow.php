<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Models\OrganisationPayoutPolicy;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Validation\ValidationException;

final class PayoutScheduleWindow
{
    public function expiresAt(CarbonImmutable $due): CarbonImmutable
    {
        return $due->utc()->addDay();
    }

    public function due(OrganisationPayoutPolicy $policy, CarbonImmutable $now): CarbonImmutable
    {
        $local = $now->setTimezone($policy->timezone);
        if ($policy->cadence === 'weekly') {
            $local = $local->subDays(($local->dayOfWeek - $policy->weekday + 7) % 7);
        }
        $wall = $local->format('Y-m-d').' '.$policy->local_time;
        $naive = CarbonImmutable::createFromFormat('!Y-m-d H:i', $wall, 'UTC')->timestamp;
        $transitions = (new DateTimeZone($policy->timezone))->getTransitions($naive - 172800, $naive + 172800);
        $candidates = [];
        $previousOffset = null;
        foreach ($transitions ?: [] as $transition) {
            $candidate = CarbonImmutable::createFromTimestampUTC($naive - $transition['offset'])->setTimezone($policy->timezone);
            if ($candidate->format('Y-m-d H:i') === $wall) {
                $candidates[] = $candidate;
            }
            if ($previousOffset !== null && $transition['offset'] > $previousOffset
                && $naive >= $transition['ts'] + $previousOffset && $naive < $transition['ts'] + $transition['offset']) {
                return CarbonImmutable::createFromTimestampUTC($transition['ts'])->setTimezone($policy->timezone);
            }
            $previousOffset = $transition['offset'];
        }
        usort($candidates, fn (CarbonImmutable $left, CarbonImmutable $right): int => $left->timestamp <=> $right->timestamp);

        return $candidates[0] ?? throw ValidationException::withMessages(['schedule' => 'The local schedule time cannot be resolved. Review its timezone.']);
    }
}
