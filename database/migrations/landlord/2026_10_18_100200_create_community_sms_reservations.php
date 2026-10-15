<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('landlord')->create('community_sms_reservations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('source_type', 30);
            $table->uuid('source_id');
            $table->foreignUuid('credit_receipt_id')->constrained('prepaid_credit_receipts');
            $table->unsignedInteger('credits')->default(1);
            $table->timestampTz('reserved_at');
            $table->char('budget_month', 7);
            $table->string('status', 20)->default('reserved');
            $table->string('reason')->nullable();
            $table->string('provider_reference')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'source_type', 'source_id'], 'community_sms_source_unique');
            $table->index(['tenant_id', 'budget_month', 'status']);
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->dropIfExists('community_sms_reservations');
    }
};
