<?php

declare(strict_types=1);

namespace App\Services\Finance;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Gives every payout, ledger account and ledger transaction that already
 * exists an accounting category. Run once, by the migration that adds the
 * column, while the column is still nullable.
 *
 * Marketplace postings used to share accounts with other money (an event's
 * ticket sales, or a commission credit payout), so those accounts are split:
 * the marketplace entries move to accounts of their own.
 */
final class LedgerCategoryBackfill
{
    public function __construct(private readonly ConnectionInterface $db) {}

    public static function onLandlord(): self
    {
        return new self(DB::connection('landlord'));
    }

    public function run(): void
    {
        $this->labelTransactions();
        $this->splitMarketplaceAccounts();
        $this->labelAccounts();
        $this->labelPayouts();
        $this->assertNothingLeftUnlabelled();
        $this->assertEntriesMatchTheirTransactions();
    }

    private function labelTransactions(): void
    {
        $this->db->update("UPDATE ledger_transactions SET category = 'marketplace' WHERE category IS NULL AND transaction_type = 'marketplace_booking'");
        $this->db->update("UPDATE ledger_transactions SET category = 'commission_credit' WHERE category IS NULL AND event_id IS NULL AND transaction_type = 'payout'");
        $this->db->update("UPDATE ledger_transactions SET category = 'event' WHERE category IS NULL AND event_id IS NOT NULL");
    }

    private function splitMarketplaceAccounts(): void
    {
        $this->db->statement(<<<'SQL'
            INSERT INTO ledger_accounts (id, tenant_id, event_id, code, name, type, currency, is_active, category, created_at, updated_at)
            SELECT gen_random_uuid(), a.tenant_id, a.event_id, a.code, a.name, a.type, a.currency, a.is_active, 'marketplace', now(), now()
            FROM ledger_accounts a
            WHERE a.category IS NULL
              AND a.id IN (
                  SELECT e.account_id
                  FROM ledger_entries e
                  JOIN ledger_transactions t ON t.id = e.transaction_id
                  WHERE t.category = 'marketplace'
              )
              AND NOT EXISTS (
                  SELECT 1 FROM ledger_accounts m
                  WHERE m.category = 'marketplace'
                    AND m.tenant_id = a.tenant_id
                    AND m.event_id IS NOT DISTINCT FROM a.event_id
                    AND m.code = a.code
                    AND m.currency = a.currency
              )
            SQL);

        $this->db->statement(<<<'SQL'
            UPDATE ledger_entries e
            SET account_id = m.id
            FROM ledger_transactions t, ledger_accounts a, ledger_accounts m
            WHERE t.id = e.transaction_id
              AND t.category = 'marketplace'
              AND a.id = e.account_id
              AND a.category IS NULL
              AND m.category = 'marketplace'
              AND m.tenant_id = a.tenant_id
              AND m.event_id IS NOT DISTINCT FROM a.event_id
              AND m.code = a.code
              AND m.currency = a.currency
            SQL);
    }

    private function labelAccounts(): void
    {
        // What is left of an event-less account after the split and has no
        // entries is a leftover of the split.
        $this->db->statement('DELETE FROM ledger_accounts a WHERE a.category IS NULL AND a.event_id IS NULL AND NOT EXISTS (SELECT 1 FROM ledger_entries e WHERE e.account_id = a.id)');
        $this->db->update("UPDATE ledger_accounts SET category = 'commission_credit' WHERE category IS NULL AND event_id IS NULL");
        $this->db->update("UPDATE ledger_accounts SET category = 'event' WHERE category IS NULL AND event_id IS NOT NULL");
    }

    private function labelPayouts(): void
    {
        $this->db->update("UPDATE event_payouts SET category = 'event' WHERE category IS NULL AND event_id IS NOT NULL");
    }

    private function assertNothingLeftUnlabelled(): void
    {
        $unlabelled = [];

        foreach (['ledger_transactions', 'ledger_accounts', 'event_payouts'] as $table) {
            $count = (int) $this->db->table($table)->whereNull('category')->count();

            if ($count > 0) {
                $unlabelled[] = "{$count} row(s) in {$table}";
            }
        }

        if ($unlabelled !== []) {
            throw new RuntimeException('Could not give a ledger category to '.implode(' and ', $unlabelled).'. Classify them by hand before migrating.');
        }
    }

    private function assertEntriesMatchTheirTransactions(): void
    {
        $mismatched = (int) $this->db->table('ledger_entries as e')
            ->join('ledger_accounts as a', 'a.id', '=', 'e.account_id')
            ->join('ledger_transactions as t', 't.id', '=', 'e.transaction_id')
            ->whereColumn('a.category', '<>', 't.category')
            ->count();

        if ($mismatched > 0) {
            throw new RuntimeException("{$mismatched} ledger entr(ies) sit in an account of a different category from their transaction. Resolve them by hand before migrating.");
        }
    }
}
