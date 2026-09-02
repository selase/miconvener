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
        Schema::connection('landlord')->create('llm_usage_summaries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('uuid')->unique();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();

            $table->string('provider');
            $table->string('model');
            $table->date('day');

            $table->bigInteger('total_prompt_tokens')->default(0);
            $table->bigInteger('total_completion_tokens')->default(0);
            $table->bigInteger('total_total_tokens')->default(0);
            $table->decimal('total_cost_usd', 12, 6)->default(0);
            $table->integer('request_count')->default(0);

            $table->timestamps();

            $table->unique(['tenant_id', 'provider', 'model', 'day'], 'llm_usage_summary_unique');
            $table->index('day');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('landlord')->dropIfExists('llm_usage_summaries');
    }
};
