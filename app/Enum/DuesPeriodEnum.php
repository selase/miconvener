<?php

declare(strict_types=1);

namespace App\Enum;

use App\Models\CommunitySettings;
use Carbon\CarbonImmutable;

/** How often a dues class bills its members. */
enum DuesPeriodEnum: string
{
    case Daily = 'daily';
    case Weekly = 'weekly';
    case Monthly = 'monthly';
    case Quarterly = 'quarterly';
    case Yearly = 'yearly';

    public function label(): string
    {
        return match ($this) {
            self::Daily => 'Daily',
            self::Weekly => 'Weekly',
            self::Monthly => 'Monthly',
            self::Quarterly => 'Quarterly',
            self::Yearly => 'Yearly',
        };
    }

    /** The anchor on or before a date already expressed in the organisation's timezone. */
    public function periodStartFor(CarbonImmutable $localDate, CommunitySettings $s): CarbonImmutable
    {
        $date = $localDate->startOfDay();

        return match ($this) {
            self::Daily => $date,
            self::Weekly => $date->subDays(($date->dayOfWeekIso - $s->week_starts_on + 7) % 7),
            self::Monthly => $date->startOfMonth(),
            self::Quarterly => $date->startOfQuarter(),
            self::Yearly => $date->setDate(
                $date->year - ($date->month < $s->financial_year_start_month ? 1 : 0),
                $s->financial_year_start_month,
                1,
            ),
        };
    }

    /** The first day of the period following the supplied calendar anchor. */
    public function nextStart(CarbonImmutable $start, CommunitySettings $s): CarbonImmutable
    {
        $start = $this->periodStartFor($start, $s);

        return match ($this) {
            self::Daily => $start->addDay(),
            self::Weekly => $start->addWeek(),
            self::Monthly => $start->addMonthNoOverflow(),
            self::Quarterly => $start->addMonthsNoOverflow(3),
            self::Yearly => $start->addYearNoOverflow(),
        };
    }

    /** The inclusive last day of the period starting on the supplied anchor. */
    public function periodEnd(CarbonImmutable $start, CommunitySettings $s): CarbonImmutable
    {
        return $this->nextStart($start, $s)->subDay();
    }
}
