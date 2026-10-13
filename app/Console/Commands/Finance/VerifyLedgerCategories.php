<?php

declare(strict_types=1);

namespace App\Console\Commands\Finance;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Read-only check, run after deploying the accounting categories: shows how
 * many rows are in each category and proves every ledger entry sits in an
 * account of its own transaction's category.
 */
final class VerifyLedgerCategories extends Command
{
    protected $signature = 'finance:verify-categories';

    protected $description = 'Show the rows in each accounting category and check every ledger entry sits in an account of its transaction\'s category';

    public function handle(): int
    {
        $db = DB::connection('landlord');

        foreach (['event_payouts', 'ledger_accounts', 'ledger_transactions'] as $table) {
            $rows = $db->table($table)
                ->select('category', DB::raw('count(*) as total'))
                ->groupBy('category')
                ->orderBy('category')
                ->get();

            $this->table([$table, 'rows'], $rows->map(fn (object $row): array => [$row->category, $row->total])->all());
        }

        $mismatched = (int) $db->table('ledger_entries as e')
            ->join('ledger_accounts as a', 'a.id', '=', 'e.account_id')
            ->join('ledger_transactions as t', 't.id', '=', 'e.transaction_id')
            ->whereColumn('a.category', '<>', 't.category')
            ->count();

        if ($mismatched > 0) {
            $this->error("{$mismatched} ledger entries sit in an account of a different category from their transaction.");

            return self::FAILURE;
        }

        $this->info('Every ledger entry sits in an account of its transaction\'s category.');

        return self::SUCCESS;
    }
}
