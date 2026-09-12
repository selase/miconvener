<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Models\Event;
use App\Models\EventPayout;
use App\Models\EventRegistration;
use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\LedgerTransaction;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class LedgerService
{
    /**
     * Ensures all standard chart-of-accounts exist for the tenant & event in this currency.
     *
     * @return array<string, LedgerAccount>
     */
    public function ensureAccountsExist(Tenant $tenant, ?Event $event = null, string $currency = 'GHS'): array
    {
        $definitions = [
            LedgerAccount::CODE_GATEWAY_CLEARING => [
                'name' => 'Payment Gateway Clearing',
                'type' => LedgerAccount::TYPE_ASSET,
            ],
            LedgerAccount::CODE_BANK => [
                'name' => 'Cash / Bank Account',
                'type' => LedgerAccount::TYPE_ASSET,
            ],
            LedgerAccount::CODE_ORGANIZER_PAYABLE => [
                'name' => 'Organizer Payable (Available Balance)',
                'type' => LedgerAccount::TYPE_LIABILITY,
            ],
            LedgerAccount::CODE_HOLDBACK_RESERVE => [
                'name' => 'Holdback Reserve (Escrow)',
                'type' => LedgerAccount::TYPE_LIABILITY,
            ],
            LedgerAccount::CODE_PLATFORM_REVENUE => [
                'name' => 'Platform Commission Revenue',
                'type' => LedgerAccount::TYPE_REVENUE,
            ],
            LedgerAccount::CODE_GATEWAY_FEES => [
                'name' => 'Payment Processing Fees',
                'type' => LedgerAccount::TYPE_EXPENSE,
            ],
        ];

        $accounts = [];
        $eventId = $event?->id;

        foreach ($definitions as $code => $def) {
            $account = LedgerAccount::on('landlord')->firstOrCreate([
                'tenant_id' => $tenant->id,
                'event_id' => $eventId,
                'code' => $code,
                'currency' => $currency,
            ], [
                'name' => $def['name'],
                'type' => $def['type'],
                'is_active' => true,
            ]);

            $accounts[$code] = $account;
        }

        return $accounts;
    }

    /**
     * Posts a double-entry balanced transaction.
     * Enforces the fundamental invariant: Sum(Debits) === Sum(Credits).
     *
     * @param  array<int, array{code: string, direction: string, amount: int, description?: string}>  $entries
     */
    public function postTransaction(
        Tenant $tenant,
        ?Event $event,
        string $transactionType,
        string $description,
        string $reference,
        array $entries,
        ?string $userId = null,
        string $currency = 'GHS'
    ): LedgerTransaction {
        if (empty($entries)) {
            throw new InvalidArgumentException('Cannot post an empty ledger transaction.');
        }

        $totalDebits = 0;
        $totalCredits = 0;

        foreach ($entries as $e) {
            if (($e['amount'] ?? 0) <= 0) {
                throw new InvalidArgumentException('Ledger entry amounts must be positive integers.');
            }

            if ($e['direction'] === LedgerEntry::DIRECTION_DEBIT) {
                $totalDebits += (int) $e['amount'];
            } elseif ($e['direction'] === LedgerEntry::DIRECTION_CREDIT) {
                $totalCredits += (int) $e['amount'];
            } else {
                throw new InvalidArgumentException("Invalid ledger direction: {$e['direction']}. Must be debit or credit.");
            }
        }

        if ($totalDebits !== $totalCredits) {
            throw new InvalidArgumentException(
                "Unbalanced ledger transaction! Debits ({$totalDebits}) must exactly equal Credits ({$totalCredits})."
            );
        }

        $accounts = $this->ensureAccountsExist($tenant, $event, $currency);

        return DB::connection('landlord')->transaction(function () use ($tenant, $event, $transactionType, $description, $reference, $entries, $userId, $currency, $accounts): LedgerTransaction {
            $transaction = LedgerTransaction::create([
                'tenant_id' => $tenant->id,
                'event_id' => $event?->id,
                'reference' => $reference,
                'description' => $description,
                'transaction_type' => $transactionType,
                'posted_at' => now(),
                'created_by' => $userId,
            ]);

            foreach ($entries as $entryData) {
                $account = $accounts[$entryData['code']] ?? null;
                if (! $account) {
                    throw new InvalidArgumentException("Unknown account code: {$entryData['code']}");
                }

                LedgerEntry::create([
                    'transaction_id' => $transaction->id,
                    'account_id' => $account->id,
                    'direction' => $entryData['direction'],
                    'amount' => (int) $entryData['amount'],
                    'currency' => $currency,
                    'description' => $entryData['description'] ?? null,
                ]);
            }

            return $transaction;
        });
    }

    /**
     * Record a ticket sale:
     * Debit:  Payment Gateway Clearing (gross)
     * Credit: Organizer Payable (net to organizer)
     * Credit: Platform Revenue (commission)
     */
    public function recordTicketSale(
        Event $event,
        EventRegistration $registration,
        int $grossAmount,
        int $platformFee,
        string $reference
    ): ?LedgerTransaction {
        if ($grossAmount <= 0) {
            return null; // Free tickets do not move financial cash
        }

        $tenant = $event->tenant ?? Tenant::find($event->tenant_id);
        $netAmount = $grossAmount - $platformFee;

        $entries = [
            [
                'code' => LedgerAccount::CODE_GATEWAY_CLEARING,
                'direction' => LedgerEntry::DIRECTION_DEBIT,
                'amount' => $grossAmount,
                'description' => "Ticket payment: {$registration->full_name} ({$registration->email})",
            ],
            [
                'code' => LedgerAccount::CODE_ORGANIZER_PAYABLE,
                'direction' => LedgerEntry::DIRECTION_CREDIT,
                'amount' => $netAmount,
                'description' => 'Net ticket revenue payable to organizer',
            ],
        ];

        if ($platformFee > 0) {
            $entries[] = [
                'code' => LedgerAccount::CODE_PLATFORM_REVENUE,
                'direction' => LedgerEntry::DIRECTION_CREDIT,
                'amount' => $platformFee,
                'description' => 'Platform commission fee ('.$event->effectivePlatformFeePercentage().'%)',
            ];
        }

        return $this->postTransaction(
            $tenant,
            $event,
            LedgerTransaction::TYPE_TICKET_SALE,
            "Ticket Sale: {$event->name} - {$registration->full_name}",
            $reference,
            $entries,
            null,
            $event->currency ?: 'GHS'
        );
    }

    /**
     * Record a ticket refund:
     * Debit:  Organizer Payable (net amount deducted)
     * Debit:  Platform Revenue (platform fee reversed, if any)
     * Credit: Payment Gateway Clearing (gross refunded to buyer)
     */
    public function recordRefund(
        Event $event,
        EventRegistration $registration,
        int $grossAmount,
        int $platformFee,
        string $reference
    ): ?LedgerTransaction {
        if ($grossAmount <= 0) {
            return null;
        }

        $tenant = $event->tenant ?? Tenant::find($event->tenant_id);
        $netAmount = $grossAmount - $platformFee;

        $entries = [
            [
                'code' => LedgerAccount::CODE_ORGANIZER_PAYABLE,
                'direction' => LedgerEntry::DIRECTION_DEBIT,
                'amount' => $netAmount,
                'description' => "Refund ticket: {$registration->full_name}",
            ],
            [
                'code' => LedgerAccount::CODE_GATEWAY_CLEARING,
                'direction' => LedgerEntry::DIRECTION_CREDIT,
                'amount' => $grossAmount,
                'description' => "Refund disbursed to customer: {$registration->email}",
            ],
        ];

        if ($platformFee > 0) {
            $entries[] = [
                'code' => LedgerAccount::CODE_PLATFORM_REVENUE,
                'direction' => LedgerEntry::DIRECTION_DEBIT,
                'amount' => $platformFee,
                'description' => 'Platform fee reversal on refund',
            ];
        }

        return $this->postTransaction(
            $tenant,
            $event,
            LedgerTransaction::TYPE_REFUND,
            "Refund: {$event->name} - {$registration->full_name}",
            $reference,
            $entries,
            null,
            $event->currency ?: 'GHS'
        );
    }

    /**
     * Record holdback reserve retention:
     * Debit:  Organizer Payable (reduces available balance)
     * Credit: Holdback Reserve (held in escrow)
     */
    public function recordHoldbackRetention(
        Event $event,
        int $amount,
        string $reference,
        ?string $description = null
    ): LedgerTransaction {
        $tenant = $event->tenant ?? Tenant::find($event->tenant_id);

        $entries = [
            [
                'code' => LedgerAccount::CODE_ORGANIZER_PAYABLE,
                'direction' => LedgerEntry::DIRECTION_DEBIT,
                'amount' => $amount,
                'description' => 'Holdback reserve retained from available balance',
            ],
            [
                'code' => LedgerAccount::CODE_HOLDBACK_RESERVE,
                'direction' => LedgerEntry::DIRECTION_CREDIT,
                'amount' => $amount,
                'description' => 'Holdback escrow reserve held for dispute/refund buffer',
            ],
        ];

        return $this->postTransaction(
            $tenant,
            $event,
            LedgerTransaction::TYPE_HOLDBACK_RETENTION,
            $description ?: "Holdback Retention: {$event->name}",
            $reference,
            $entries,
            null,
            $event->currency ?: 'GHS'
        );
    }

    /**
     * Record holdback reserve release:
     * Debit:  Holdback Reserve
     * Credit: Organizer Payable (restores into available balance)
     */
    public function recordHoldbackRelease(
        Event $event,
        int $amount,
        string $reference,
        ?string $description = null
    ): LedgerTransaction {
        $tenant = $event->tenant ?? Tenant::find($event->tenant_id);

        $entries = [
            [
                'code' => LedgerAccount::CODE_HOLDBACK_RESERVE,
                'direction' => LedgerEntry::DIRECTION_DEBIT,
                'amount' => $amount,
                'description' => 'Holdback escrow reserve released',
            ],
            [
                'code' => LedgerAccount::CODE_ORGANIZER_PAYABLE,
                'direction' => LedgerEntry::DIRECTION_CREDIT,
                'amount' => $amount,
                'description' => 'Holdback released into available organizer balance',
            ],
        ];

        return $this->postTransaction(
            $tenant,
            $event,
            LedgerTransaction::TYPE_HOLDBACK_RELEASE,
            $description ?: "Holdback Release: {$event->name}",
            $reference,
            $entries,
            null,
            $event->currency ?: 'GHS'
        );
    }

    /**
     * Record a payout disbursement:
     * Debit:  Organizer Payable (settles liability)
     * Credit: Bank / Clearing (cash disbursed to organizer bank account)
     */
    public function recordPayout(
        Event $event,
        int $amount,
        string $reference,
        ?EventPayout $payout = null
    ): LedgerTransaction {
        $tenant = $event->tenant ?? Tenant::find($event->tenant_id);

        $entries = [
            [
                'code' => LedgerAccount::CODE_ORGANIZER_PAYABLE,
                'direction' => LedgerEntry::DIRECTION_DEBIT,
                'amount' => $amount,
                'description' => 'Payout settlement deducted from organizer payable',
            ],
            [
                'code' => LedgerAccount::CODE_BANK,
                'direction' => LedgerEntry::DIRECTION_CREDIT,
                'amount' => $amount,
                'description' => 'Cash transfer to organizer verified bank account',
            ],
        ];

        return $this->postTransaction(
            $tenant,
            $event,
            LedgerTransaction::TYPE_PAYOUT,
            "Payout Disbursement: {$event->name} (#{$reference})",
            $reference,
            $entries,
            null,
            $event->currency ?: 'GHS'
        );
    }

    /**
     * Returns the complete Trial Balance verifying ledger equilibrium.
     *
     * @return array<string, mixed>
     */
    public function getTrialBalance(Event $event): array
    {
        $tenant = $event->tenant ?? Tenant::find($event->tenant_id);
        $accounts = $this->ensureAccountsExist($tenant, $event, $event->currency ?: 'GHS');

        $rows = [];
        $totalDebits = 0;
        $totalCredits = 0;

        foreach ($accounts as $code => $account) {
            $debits = (int) $account->entries()->where('direction', LedgerEntry::DIRECTION_DEBIT)->sum('amount');
            $credits = (int) $account->entries()->where('direction', LedgerEntry::DIRECTION_CREDIT)->sum('amount');
            $balance = $account->currentBalance();

            $totalDebits += $debits;
            $totalCredits += $credits;

            $rows[] = [
                'id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
                'type' => $account->type,
                'debits' => $debits,
                'credits' => $credits,
                'balance' => $balance,
            ];
        }

        return [
            'is_balanced' => $totalDebits === $totalCredits,
            'total_debits' => $totalDebits,
            'total_credits' => $totalCredits,
            'accounts' => $rows,
        ];
    }
}
