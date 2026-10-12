<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Commission the platform owes an organizer back (a refund on their own
     * Paystack account does not return our share), held as a credit that
     * later sales net against. One balance per organizer and currency, in
     * minor units.
     */
    public function up(): void
    {
        Schema::connection('landlord')->create('tenant_commission_credits', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('uuid')->unique();

            $table->foreignUuid('tenant_id')->constrained('tenants')->onDelete('cascade');
            $table->string('currency', 3);
            $table->unsignedBigInteger('amount')->default(0);

            $table->timestamps();

            $table->unique(['tenant_id', 'currency']);
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->dropIfExists('tenant_commission_credits');
    }
};
