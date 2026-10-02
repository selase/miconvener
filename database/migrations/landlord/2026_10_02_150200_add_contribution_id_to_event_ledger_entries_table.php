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
        Schema::connection('landlord')->table('event_ledger_entries', function (Blueprint $table): void {
            $table->foreignUuid('contribution_id')
                ->nullable()
                ->after('payout_id')
                ->constrained('event_contributions')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('landlord')->table('event_ledger_entries', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('contribution_id');
        });
    }
};
