<?php

declare(strict_types=1);

namespace App\Services\Marketplace;

use App\Models\Event;
use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\Tenant;
use App\Services\Finance\LedgerService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Books a marketplace payment (a venue booking or a vendor quote) to the
 * seller's ledger: cash in from Paystack, MiConvener's commission and the
 * buyer's service fee to platform revenue, and the rest owed to the business.
 */
final class MarketplaceLedger
{
    public function __construct(
        private readonly LedgerService $ledger,
        private readonly MarketplaceFees $fees,
    ) {}

    public function post(
        Tenant $seller,
        ?Event $event,
        string $label,
        string $reference,
        int $amountPaid,
        int $gatewayFee,
        int $buyerFee,
    ): void {
        $split = $this->fees->split($amountPaid, $gatewayFee, $buyerFee);
        $percent = mb_rtrim(mb_rtrim(number_format($this->fees->commissionPercent(), 2, '.', ''), '0'), '.');

        $entries = array_values(array_filter([
            [
                'code' => LedgerAccount::CODE_GATEWAY_CLEARING,
                'direction' => LedgerEntry::DIRECTION_DEBIT,
                'amount' => $split['clearing'],
                'description' => "Cash clearing from Paystack for {$label} (net of gateway fee)",
            ],
            [
                'code' => LedgerAccount::CODE_PLATFORM_REVENUE,
                'direction' => LedgerEntry::DIRECTION_CREDIT,
                'amount' => $split['platform'],
                'description' => "Platform commission ({$percent}%) and buyer service fee on {$label}",
            ],
            [
                'code' => LedgerAccount::CODE_ORGANIZER_PAYABLE,
                'direction' => LedgerEntry::DIRECTION_CREDIT,
                'amount' => $split['payable'],
                'description' => "Business payable balance for {$label}",
            ],
        ], fn (array $entry): bool => $entry['amount'] > 0));

        if ($entries === []) {
            return;
        }

        try {
            $this->ledger->postTransaction(
                tenant: $seller,
                event: $event,
                transactionType: 'marketplace_booking',
                description: "Marketplace payment: {$label}",
                reference: $reference,
                entries: $entries,
            );
        } catch (Throwable $e) {
            Log::error('Failed to post marketplace payment to ledger', [
                'reference' => $reference,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
