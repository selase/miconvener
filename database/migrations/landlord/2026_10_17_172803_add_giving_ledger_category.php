<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->constraints(true);
        Schema::connection('landlord')->table('ledger_transactions', function (Blueprint $t): void {
            $t->foreignUuid('giving_fund_id')->nullable()->constrained('giving_funds')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        foreach (['ledger_accounts', 'ledger_transactions', 'event_payouts'] as $table) {
            if (DB::connection('landlord')->table($table)->where('category', 'giving')->exists()) {
                throw new RuntimeException('Giving financial records exist; category support cannot be removed.');
            }
        }
        Schema::connection('landlord')->table('ledger_transactions', fn (Blueprint $t) => $t->dropConstrainedForeignId('giving_fund_id'));
        $this->constraints(false);
    }

    private function constraints(bool $giving): void
    {
        $extra = $giving ? ",'giving'" : '';
        foreach (['ledger_accounts', 'ledger_transactions', 'event_payouts'] as $table) {
            $db = DB::connection('landlord');
            $db->statement("ALTER TABLE {$table} DROP CONSTRAINT {$table}_category_check");
            $db->statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_category_check CHECK (category IN ('event','dues','commission_credit','marketplace'{$extra}) AND (category <> 'event' OR event_id IS NOT NULL) AND (category NOT IN ('dues','commission_credit'{$extra}) OR event_id IS NULL))");
        }
    }
};
