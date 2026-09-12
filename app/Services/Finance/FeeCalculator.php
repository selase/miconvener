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
        if ($ticketAmount < 0) {
            throw new InvalidArgumentException('Ticket amounts cannot be negative.');
        }

        $percentage = $this->resolver->percentageFor($event);
        $bearer = $this->resolver->bearerFor($event);
        $cap = $this->resolver->capAmountFor($event);

        if ($ticketAmount === 0) {
            return new FeeBreakdown(0, 0, 0, 0, 0, $bearer, $percentage, false);
        }

        $uncappedFee = (int) round($ticketAmount * $percentage / 100);
        $platformFee = $cap !== null ? min($uncappedFee, $cap) : $uncappedFee;
        $capApplied = $cap !== null && $uncappedFee > $cap;

        $chargedAmount = $bearer === PlatformFeeResolver::BEARER_ATTENDEE
            ? $ticketAmount + $platformFee
            : $ticketAmount;

        /**
         * Paystack takes its cut of whatever it actually collects, so the
         * estimate follows the charged amount rather than the ticket price.
         */
        $gatewayFeeEstimate = (int) round(
            $chargedAmount * $this->resolver->gatewayFeePercentage() / 100
        );

        return new FeeBreakdown(
            ticketAmount: $ticketAmount,
            platformFee: $platformFee,
            gatewayFeeEstimate: $gatewayFeeEstimate,
            chargedAmount: $chargedAmount,
            organizerNet: $chargedAmount - $platformFee - $gatewayFeeEstimate,
            bearer: $bearer,
            percentage: $percentage,
            capApplied: $capApplied,
        );
    }
}
