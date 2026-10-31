<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fixed USD plan prices shown to visitors outside Ghana, and the currency a
 * subscription was bought in, so its renewals are priced the same way.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('landlord')->table('packages', function (Blueprint $table): void {
            $table->decimal('price_usd', 10, 2)->nullable()->after('yearly_price');
            $table->decimal('yearly_price_usd', 10, 2)->nullable()->after('price_usd');
        });
        Schema::connection('landlord')->table('subscriptions', function (Blueprint $table): void {
            $table->char('billing_currency', 3)->default('GHS');
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->table('subscriptions', fn (Blueprint $table) => $table->dropColumn('billing_currency'));
        Schema::connection('landlord')->table('packages', fn (Blueprint $table) => $table->dropColumn(['price_usd', 'yearly_price_usd']));
    }
};
