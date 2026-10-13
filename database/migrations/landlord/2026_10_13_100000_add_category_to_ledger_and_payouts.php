<?php

declare(strict_types=1);

use App\Services\Finance\LedgerCategoryBackfill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const array TABLES = ['event_payouts', 'ledger_accounts', 'ledger_transactions'];

    /**
     * Every payout, ledger account and ledger transaction gets an accounting
     * category, so money that is not tied to an event (member dues) can be
     * paid out and still be accounted for. The check constraint ties the
     * category to the event: `event` needs one, `dues` and `commission_credit`
     * have none, `marketplace` may or may not.
     */
    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::connection('landlord')->table($table, function (Blueprint $blueprint): void {
                $blueprint->string('category', 32)->nullable();
            });
        }

        LedgerCategoryBackfill::onLandlord()->run();

        $db = DB::connection('landlord');

        foreach (self::TABLES as $table) {
            $db->statement("ALTER TABLE {$table} ALTER COLUMN category SET NOT NULL");
            $db->statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_category_check CHECK (
                category IN ('event', 'dues', 'commission_credit', 'marketplace')
                AND (category <> 'event' OR event_id IS NOT NULL)
                AND (category NOT IN ('dues', 'commission_credit') OR event_id IS NULL)
            )");

            Schema::connection('landlord')->table($table, function (Blueprint $blueprint): void {
                $blueprint->index('category');
            });
        }

        $db->statement('ALTER TABLE event_payouts ALTER COLUMN event_id DROP NOT NULL');
    }

    /**
     * Schema only. Accounts split out for marketplace postings stay as they are,
     * and this refuses to run while any payout has no event.
     */
    public function down(): void
    {
        $db = DB::connection('landlord');

        $db->statement('ALTER TABLE event_payouts ALTER COLUMN event_id SET NOT NULL');

        foreach (self::TABLES as $table) {
            Schema::connection('landlord')->table($table, function (Blueprint $blueprint): void {
                $blueprint->dropIndex(['category']);
            });

            $db->statement("ALTER TABLE {$table} DROP CONSTRAINT {$table}_category_check");

            Schema::connection('landlord')->table($table, function (Blueprint $blueprint): void {
                $blueprint->dropColumn('category');
            });
        }
    }
};
