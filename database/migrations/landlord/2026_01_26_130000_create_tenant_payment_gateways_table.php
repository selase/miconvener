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
        Schema::connection('landlord')->create('tenant_payment_gateways', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('uuid')->unique();

            $table->foreignUuid('tenant_id')->constrained('tenants')->onDelete('cascade');

            $table->string('provider'); // stripe, paystack
            $table->text('api_key_encrypted');
            $table->text('public_key_encrypted')->nullable();
            $table->text('webhook_secret_encrypted')->nullable();
            $table->boolean('is_active')->default(true);
            $table->jsonb('meta')->nullable();

            $table->timestamps();

            // Limit one gateway config per provider per tenant
            $table->unique(['tenant_id', 'provider']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('landlord')->dropIfExists('tenant_payment_gateways');
    }
};
