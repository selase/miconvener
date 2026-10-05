<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Enterprise has no list price: each organisation's price is negotiated and
 * set by a superadmin. Checkout and renewals charge it, and the organisation
 * sees it on its Billing page.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('landlord')->table('tenants', function (Blueprint $table): void {
            $table->unsignedBigInteger('agreed_price_pesewas')->nullable();
            $table->string('agreed_price_interval', 5)->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->table('tenants', function (Blueprint $table): void {
            $table->dropColumn(['agreed_price_pesewas', 'agreed_price_interval']);
        });
    }
};
