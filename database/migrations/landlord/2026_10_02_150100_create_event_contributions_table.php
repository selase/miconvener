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
        Schema::connection('landlord')->create('event_contributions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('event_id')->constrained('events')->cascadeOnDelete();
            $table->string('contributor_name');
            $table->string('contributor_email')->nullable();
            $table->string('contributor_phone')->nullable();
            $table->unsignedInteger('amount'); // Minor units (pesewas)
            $table->unsignedInteger('gateway_fee_amount')->default(0);
            $table->unsignedInteger('platform_fee_amount')->default(0);
            $table->unsignedInteger('net_amount')->default(0);
            $table->string('currency', 3)->default('GHS');
            $table->string('status', 32)->default('pending_payment');
            $table->string('payment_reference')->unique();
            $table->string('paystack_reference')->nullable()->index();
            $table->string('provider', 32)->default('paystack');
            $table->text('tribute_message')->nullable();
            $table->boolean('is_anonymous')->default(false);
            $table->boolean('is_approved')->default(true);
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['event_id', 'status']);
            $table->index(['event_id', 'is_approved']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('landlord')->dropIfExists('event_contributions');
    }
};
