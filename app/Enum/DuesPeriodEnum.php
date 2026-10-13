<?php

declare(strict_types=1);

namespace App\Enum;

use Carbon\CarbonImmutable;

/** How often a dues class bills its members. */
enum DuesPeriodEnum: string
{
    case Monthly = 'monthly';
    case Quarterly = 'quarterly';
    case Yearly = 'yearly';

    public function label(): string
    {
        return match ($this) {
            self::Monthly => 'Monthly',
            self::Quarterly => 'Quarterly',
            self::Yearly => 'Yearly',
        };
    }

    /** The first day of the period that follows the one starting on $from. */
    public function nextStart(CarbonImmutable $from): CarbonImmutable
    {
        return $from->startOfDay()->addMonthsNoOverflow($this->months());
    }

    /** The last day of the period starting on $start. */
    public function periodEnd(CarbonImmutable $start): CarbonImmutable
    {
        return $this->nextStart($start)->subDay();
    }

    private function months(): int
    {
        return match ($this) {
            self::Monthly => 1,
            self::Quarterly => 3,
            self::Yearly => 12,
        };
    }
}
