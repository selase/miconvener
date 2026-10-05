<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The buyer's service fee, fixed when checkout starts so the amount charged,
 * the amount shown and the amount booked to the ledger are the same figure.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('landlord')->table('venue_bookings', function (Blueprint $table): void {
            $table->unsignedBigInteger('buyer_fee_pesewas')->default(0);
        });

        Schema::connection('landlord')->table('marketplace_quotes', function (Blueprint $table): void {
            $table->unsignedBigInteger('buyer_fee_pesewas')->default(0);
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->table('venue_bookings', function (Blueprint $table): void {
            $table->dropColumn('buyer_fee_pesewas');
        });

        Schema::connection('landlord')->table('marketplace_quotes', function (Blueprint $table): void {
            $table->dropColumn('buyer_fee_pesewas');
        });
    }
};
