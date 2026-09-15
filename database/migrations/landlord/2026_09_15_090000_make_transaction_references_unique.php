<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A provider reference names one payment, so two rows with the same reference
 * are one payment recorded twice — which the browser callback and the webhook
 * could each produce. The index turns a race between them into an error the
 * fulfilment code catches, instead of a duplicate. Production had no duplicate
 * references when this was written; the check stops the deploy if that changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        $duplicates = DB::connection('landlord')->table('transactions')
            ->select('provider_transaction_id')
            ->groupBy('provider_transaction_id')
            ->havingRaw('count(*) > 1')
            ->pluck('provider_transaction_id');

        if ($duplicates->isNotEmpty()) {
            throw new RuntimeException('Resolve duplicate transaction references before adding the unique index: '.$duplicates->implode(', '));
        }

        Schema::connection('landlord')->table('transactions', function (Blueprint $table): void {
            $table->unique('provider_transaction_id', 'transactions_provider_transaction_id_unique');
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->table('transactions', function (Blueprint $table): void {
            $table->dropUnique('transactions_provider_transaction_id_unique');
        });
    }
};
