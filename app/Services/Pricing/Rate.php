<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use Carbon\CarbonImmutable;

/**
 * One USD to GHS rate: its value, the date it was published for, and where it
 * came from.
 */
final readonly class Rate
{
    public function __construct(public float $rate, public CarbonImmutable $asOf, public string $source) {}

    /** How a rate is written for people: up to four decimals, no trailing zeros ("11.77"). */
    public static function describe(float $rate): string
    {
        return mb_rtrim(mb_rtrim(number_format($rate, 4, '.', ''), '0'), '.');
    }

    /** Whole calendar days between the published date and today. */
    public function ageDays(): int
    {
        return (int) $this->asOf->startOfDay()->diffInDays(CarbonImmutable::now()->startOfDay());
    }
}
