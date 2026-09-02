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
        Schema::connection('landlord')->create('usage_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->timestamp('occurred_at');
            $table->string('type'); // e.g., requests.count, jobs.runtime_ms
            $table->string('key')->nullable(); // e.g., route name, job class
            $table->decimal('quantity', 20, 4);
            $table->string('unit'); // count, ms, bytes
            $table->jsonb('meta')->nullable(); // status_code, method, etc.
            $table->timestamps();

            $table->index(['tenant_id', 'type', 'occurred_at']);
        });

        Schema::connection('landlord')->create('usage_rollups', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->enum('period', ['minute', 'hour', 'day']);
            $table->timestamp('period_start');
            $table->string('metric'); // UsageMetric enum value
            $table->jsonb('dimensions')->nullable();
            $table->string('dimensions_hash')->nullable(); // For unique constraint
            $table->decimal('value', 20, 4);
            $table->timestamps();

            $table->unique(['tenant_id', 'period', 'period_start', 'metric', 'dimensions_hash'], 'unique_usage_rollup');
            $table->index(['tenant_id', 'metric', 'period_start']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('landlord')->dropIfExists('usage_rollups');
        Schema::connection('landlord')->dropIfExists('usage_events');
    }
};
