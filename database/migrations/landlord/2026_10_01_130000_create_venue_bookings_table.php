<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('landlord')->create('venue_bookings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('booking_reference', 64)->unique();

            // Host Tenant & Space references
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->foreignUuid('store_listing_id')->constrained('store_listings')->cascadeOnDelete();

            // Planner / Customer attribution
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('planner_tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
            $table->string('planner_name');
            $table->string('planner_email');
            $table->string('planner_phone', 50)->nullable();
            $table->string('planner_company')->nullable();

            // Event specifications
            $table->string('event_type', 100);
            $table->unsignedInteger('guest_count');
            $table->string('layout_style', 50)->default('banquet');
            $table->text('special_requests')->nullable();

            // Schedule & Time slot
            $table->dateTimeTz('starts_at');
            $table->dateTimeTz('ends_at');
            $table->string('time_slot_type', 50)->default('hourly'); // hourly, full_day, multi_day

            // Financials (strictly integer minor units - pesewas)
            $table->string('pricing_model', 50)->default('per_day');
            $table->unsignedBigInteger('rate_pesewas')->default(0);
            $table->decimal('duration_units', 8, 2)->default(1.00);
            $table->unsignedBigInteger('rental_amount_pesewas')->default(0);
            $table->unsignedBigInteger('security_deposit_pesewas')->default(0);
            $table->unsignedBigInteger('total_amount_pesewas')->default(0);
            $table->unsignedBigInteger('deposit_required_pesewas')->default(0);
            $table->unsignedBigInteger('amount_paid_pesewas')->default(0);
            $table->unsignedBigInteger('gateway_fee_pesewas')->default(0);

            // Lifecycle & Payment states
            $table->string('status', 50)->default('pending_payment'); // pending_quote, pending_payment, confirmed, rejected, cancelled, completed
            $table->string('payment_status', 50)->default('unpaid'); // unpaid, deposit_paid, fully_paid, refunded
            $table->string('paystack_reference')->nullable()->unique();
            $table->timestamp('paid_at')->nullable();

            // Terms & Snapshot
            $table->timestamp('contract_agreed_at')->nullable();
            $table->jsonb('contract_terms_snapshot')->default('{}');
            $table->text('host_notes')->nullable();
            $table->timestamp('quote_valid_until')->nullable();

            $table->timestamps();

            // Indexes for fast querying & calendar lockouts
            $table->index(['store_listing_id', 'status', 'starts_at', 'ends_at']);
            $table->index(['shop_id', 'status']);
            $table->index(['tenant_id', 'status']);
            $table->index(['planner_email', 'status']);
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->dropIfExists('venue_bookings');
    }
};
