<?php

declare(strict_types=1);

namespace App\Services\Finance;

/**
 * The complete fee split for a single ticket, in minor units.
 *
 * `chargedAmount` is what the payment gateway collects from the buyer, which
 * exceeds the ticket price when the attendee bears the commission.
 * `organizerNet` is what the organizer is owed before any transfer fee.
 */
final readonly class FeeBreakdown
{
    public function __construct(
        public int $ticketAmount,
        public int $platformFee,
        public int $gatewayFeeEstimate,
        public int $chargedAmount,
        public int $organizerNet,
        public string $bearer,
        public float $percentage,
        public bool $capApplied,
    ) {}

    /**
     * @return array{ticket_amount: int, platform_fee: int, gateway_fee_estimate: int, charged_amount: int, organizer_net: int, bearer: string, percentage: float, cap_applied: bool}
     */
    public function toArray(): array
    {
        return [
            'ticket_amount' => $this->ticketAmount,
            'platform_fee' => $this->platformFee,
            'gateway_fee_estimate' => $this->gatewayFeeEstimate,
            'charged_amount' => $this->chargedAmount,
            'organizer_net' => $this->organizerNet,
            'bearer' => $this->bearer,
            'percentage' => $this->percentage,
            'cap_applied' => $this->capApplied,
        ];
    }
}
