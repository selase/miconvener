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

    /** Whole calendar days between the published date and today. */
    public function ageDays(): int
    {
        return (int) $this->asOf->startOfDay()->diffInDays(CarbonImmutable::now()->startOfDay());
    }
}
