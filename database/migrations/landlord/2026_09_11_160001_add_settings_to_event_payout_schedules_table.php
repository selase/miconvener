<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::connection('landlord')->table('event_payout_schedules', function (Blueprint $table) {
            if (! Schema::connection('landlord')->hasColumn('event_payout_schedules', 'auto_payout_enabled')) {
                $table->boolean('auto_payout_enabled')->default(true);
            }
            if (! Schema::connection('landlord')->hasColumn('event_payout_schedules', 'preferred_account_id')) {
                $table->foreignUuid('preferred_account_id')->nullable()->constrained('tenant_payout_accounts')->nullOnDelete();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('landlord')->table('event_payout_schedules', function (Blueprint $table) {
            if (Schema::connection('landlord')->hasColumn('event_payout_schedules', 'preferred_account_id')) {
                $table->dropForeign(['preferred_account_id']);
                $table->dropColumn('preferred_account_id');
            }
            if (Schema::connection('landlord')->hasColumn('event_payout_schedules', 'auto_payout_enabled')) {
                $table->dropColumn('auto_payout_enabled');
            }
        });
    }
};
