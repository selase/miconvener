<?php

declare(strict_types=1);

namespace App\Services\Billing;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;

final class SubscriptionPeriod
{
    /** @return array{current_period_end:CarbonImmutable,billing_anchor_day:int,billing_anchor_month:int} */
    public static function starting(string $interval, ?CarbonInterface $start = null): array
    {
        $start ??= CarbonImmutable::now();

        return [
            'current_period_end' => self::end($start, $interval, $start->day, $start->month),
            'billing_anchor_day' => $start->day,
            'billing_anchor_month' => $start->month,
        ];
    }

    public static function end(CarbonInterface $start, string $interval, int $anchorDay, int $anchorMonth): CarbonImmutable
    {
        if (! in_array($interval, ['month', 'year'], true) || $anchorDay < 1 || $anchorDay > 31 || $anchorMonth < 1 || $anchorMonth > 12) {
            throw new InvalidArgumentException('Choose a monthly or annual period with a valid calendar anchor.');
        }
        $date = CarbonImmutable::instance($start);
        $next = $interval === 'year'
            ? $date->startOfMonth()->addYear()->month($anchorMonth)
            : $date->startOfMonth()->addMonth();

        return $next->day(min($anchorDay, $next->daysInMonth))->setTimeFrom($date);
    }
}
