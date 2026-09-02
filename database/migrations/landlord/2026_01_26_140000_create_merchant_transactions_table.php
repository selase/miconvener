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
        Schema::connection('landlord')->create('merchant_transactions', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            // The organization that owns this transaction (the Merchant)
            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->onDelete('cascade');

            $table->string('provider'); // stripe, paystack
            $table->string('provider_transaction_id')->index();

            $table->bigInteger('amount'); // in cents/kobo
            $table->string('currency')->default('USD');
            $table->string('status'); // succeeded, pending, failed, refunded
            $table->string('type')->default('payment'); // payment, refund

            $table->string('customer_email')->nullable();
            $table->string('customer_name')->nullable();
            $table->string('description')->nullable();

            $table->jsonb('meta')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('landlord')->dropIfExists('merchant_transactions');
    }
};
