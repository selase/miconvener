<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Invoice fulfilment has always written a paid_at timestamp that the table never
 * had, so marking an invoice paid failed outright. The column is worth having
 * rather than dropping from the code: when an invoice was settled is part of the
 * finance record, and the status alone does not carry it.
 *
 * Existing paid invoices are backfilled from their last update, which is the
 * closest record of when they were settled.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('landlord')->table('invoices', function (Blueprint $table): void {
            $table->timestamp('paid_at')->nullable()->after('due_at');
        });

        Schema::connection('landlord')->getConnection()
            ->table('invoices')
            ->where('status', 'paid')
            ->whereNull('paid_at')
            ->update(['paid_at' => Schema::connection('landlord')->getConnection()->raw('updated_at')]);
    }

    public function down(): void
    {
        Schema::connection('landlord')->table('invoices', function (Blueprint $table): void {
            $table->dropColumn('paid_at');
        });
    }
};
