<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Models\TenantPayoutAccount;

/**
 * What it costs to move money out to an organizer.
 *
 * Paystack charges a flat fee per transfer — GHS 1 to mobile money, GHS 8 to
 * a bank — which the platform passes through at cost. Because the fee is per
 * payout rather than per ticket, batching one payout per event amortises it
 * to almost nothing.
 */
final class TransferFeeSchedule
{
    /**
     * The configured fee for a destination, in minor units. An unrecognised
     * destination falls back to the dearer bank fee, so the platform never
     * under-recovers on a destination it does not know about.
     */
    public function feeFor(string $destinationType): int
    {
        $fees = (array) config('services.platform.transfer_fees');

        return (int) ($fees[$destinationType] ?? $fees[TenantPayoutAccount::TYPE_BANK] ?? 800);
    }

    /**
     * The fee Paystack actually charged, when the transfer response carries
     * one. Null means the response reported no fee and the configured schedule
     * should stand — never zero, which would silently waive a real cost.
     *
     * @param  array{transfer_code?: string, status?: string, fee?: int|string|null}  $response
     */
    public function feeFromProviderResponse(array $response): ?int
    {
        $fee = $response['fee'] ?? null;

        return is_numeric($fee) ? (int) $fee : null;
    }
}
