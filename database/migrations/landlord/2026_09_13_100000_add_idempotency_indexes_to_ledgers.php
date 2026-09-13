<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A reference identifies one real movement of money — a payment, a refund, a
 * payout — so the same reference posting twice is always a duplicate rather
 * than a second event. Every caller already guards sequential redelivery, but
 * two concurrent webhook deliveries can both pass a status check before
 * either writes. These indexes make the second write fail instead of
 * silently doubling a balance.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('landlord')->table('ledger_transactions', function (Blueprint $table): void {
            $table->unique(['tenant_id', 'reference', 'transaction_type'], 'ledger_transactions_reference_unique');
        });

        Schema::connection('landlord')->table('event_ledger_entries', function (Blueprint $table): void {
            $table->unique(['tenant_id', 'type', 'provider_reference'], 'event_ledger_entries_reference_unique');
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->table('ledger_transactions', function (Blueprint $table): void {
            $table->dropUnique('ledger_transactions_reference_unique');
        });

        Schema::connection('landlord')->table('event_ledger_entries', function (Blueprint $table): void {
            $table->dropUnique('event_ledger_entries_reference_unique');
        });
    }
};
