<?php

declare(strict_types=1);

namespace App\Services\Finance;

use InvalidArgumentException;

final readonly class FeeTerms
{
    public function __construct(public float $percentage, public ?int $capAmount, public string $bearer, public float $gatewayEstimateRate)
    {
        foreach ([$percentage, $gatewayEstimateRate] as $rate) {
            if (! is_finite($rate) || $rate < 0 || $rate > 100 || abs($rate * 100 - round($rate * 100)) > 0.000001) {
                throw new InvalidArgumentException('Fee rates require percentages between zero and 100, with at most two decimal places.');
            }
        }
        if (($capAmount !== null && ($capAmount < 0 || $capAmount > 9007199254740991)) || ! in_array($bearer, ['organizer', 'attendee'], true)) {
            throw new InvalidArgumentException('Invalid fee cap or bearer.');
        }
    }
}
