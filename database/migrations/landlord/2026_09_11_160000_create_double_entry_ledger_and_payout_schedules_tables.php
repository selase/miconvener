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
        Schema::connection('landlord')->create('ledger_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('event_id')->nullable()->constrained('events')->cascadeOnDelete();
            $table->string('code', 32);
            $table->string('name', 128);
            $table->string('type', 32); // asset, liability, equity, revenue, expense
            $table->string('currency', 3)->default('GHS');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['tenant_id', 'event_id']);
            $table->index(['tenant_id', 'code']);
        });

        Schema::connection('landlord')->create('ledger_transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('event_id')->nullable()->constrained('events')->cascadeOnDelete();
            $table->string('reference', 128)->index();
            $table->string('description', 255);
            $table->string('transaction_type', 64); // ticket_sale, refund, holdback_retention, holdback_release, payout, adjustment
            $table->timestamp('posted_at');
            $table->uuid('created_by')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'event_id']);
            $table->index('posted_at');
        });

        Schema::connection('landlord')->create('ledger_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('transaction_id')->constrained('ledger_transactions')->cascadeOnDelete();
            $table->foreignUuid('account_id')->constrained('ledger_accounts')->cascadeOnDelete();
            $table->string('direction', 8); // debit, credit
            $table->bigInteger('amount'); // in minor units
            $table->string('currency', 3)->default('GHS');
            $table->string('description', 255)->nullable();
            $table->timestamps();

            $table->index(['transaction_id', 'direction']);
            $table->index('account_id');
        });

        Schema::connection('landlord')->create('event_payout_schedules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('event_id')->constrained('events')->cascadeOnDelete();
            $table->string('schedule_type', 32)->default('manual'); // manual, immediate, post_event
            $table->integer('days_after_event')->default(3); // e.g. T+3
            $table->integer('holdback_percentage')->default(10); // e.g. 10%
            $table->integer('holdback_release_days')->default(14); // release 14 days after event
            $table->bigInteger('minimum_payout_amount')->default(5000); // 50.00 GHS in minor units
            $table->string('status', 32)->default('active'); // active, paused, settled
            $table->boolean('auto_payout_enabled')->default(true);
            $table->foreignUuid('preferred_account_id')->nullable()->constrained('tenant_payout_accounts')->nullOnDelete();
            $table->timestamp('last_reconciled_at')->nullable();
            $table->timestamp('next_scheduled_run_at')->nullable();
            $table->timestamps();

            $table->unique(['event_id']);
            $table->index(['tenant_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('landlord')->dropIfExists('event_payout_schedules');
        Schema::connection('landlord')->dropIfExists('ledger_entries');
        Schema::connection('landlord')->dropIfExists('ledger_transactions');
        Schema::connection('landlord')->dropIfExists('ledger_accounts');
    }
};
