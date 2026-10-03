<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('landlord')->create('tenant_addons', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('addon_type', 64);
            $table->string('name');
            $table->unsignedInteger('quantity')->default(1);
            $table->unsignedBigInteger('unit_price');
            $table->unsignedBigInteger('total_price');
            $table->string('billing_interval', 32)->default('monthly');
            $table->string('status', 32)->default('active');
            $table->foreignUuid('event_id')->nullable()->constrained('events')->nullOnDelete();
            $table->string('paystack_reference', 128)->nullable()->unique();
            $table->timestamp('period_start')->nullable();
            $table->timestamp('period_end')->nullable();
            $table->jsonb('meta')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'addon_type', 'status'], 'idx_tenant_addons_tenant_type_status');
            $table->index(['event_id', 'addon_type', 'status'], 'idx_tenant_addons_event_type_status');
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->dropIfExists('tenant_addons');
    }
};
