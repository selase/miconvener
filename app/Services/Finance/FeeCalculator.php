<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Models\Event;
use InvalidArgumentException;

/**
 * Turns an event's commercial terms and a ticket price into the one fee split
 * every call site shares. Pure arithmetic: it reads no database of its own.
 */
final class FeeCalculator
{
    public function __construct(private PlatformFeeResolver $resolver) {}

    public function for(Event $event, int $ticketAmount): FeeBreakdown
    {
        return $this->forTerms($ticketAmount, new FeeTerms(
            $this->resolver->percentageFor($event), $this->resolver->capAmountFor($event),
            $this->resolver->bearerFor($event), $this->resolver->gatewayFeePercentage(),
        ));
    }

    public function forTerms(int $principal, FeeTerms $terms): FeeBreakdown
    {
        if ($principal < 0 || $principal > 9007199254740991) {
            throw new InvalidArgumentException('Amount exceeds safe minor-unit bounds.');
        }
        $uncapped = $this->percentage($principal, $terms->percentage);
        $fee = $terms->capAmount === null ? $uncapped : min($uncapped, $terms->capAmount);
        $charged = $terms->bearer === PlatformFeeResolver::BEARER_ATTENDEE ? $principal + $fee : $principal;
        if ($charged > 9007199254740991) {
            throw new InvalidArgumentException('Charged amount exceeds safe minor-unit bounds.');
        }
        $gateway = $this->percentage($charged, $terms->gatewayEstimateRate);

        return new FeeBreakdown($principal, $fee, $gateway, $charged, $charged - $fee - $gateway,
            $terms->bearer, $terms->percentage, $terms->capAmount !== null && $uncapped > $terms->capAmount);
    }

    private function percentage(int $amount, float $rate): int
    {
        $basisPoints = (int) round($rate * 100);

        return intdiv($amount, 10000) * $basisPoints + intdiv(($amount % 10000) * $basisPoints + 5000, 10000);
    }
}
