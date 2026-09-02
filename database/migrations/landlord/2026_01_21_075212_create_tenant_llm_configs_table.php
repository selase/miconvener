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
        Schema::connection('landlord')->create('tenant_llm_configs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('uuid')->unique();

            $table->foreignUuid('tenant_id')->constrained('tenants')->onDelete('cascade');

            $table->string('provider'); // openai, anthropic, google
            $table->text('api_key_encrypted');
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            // Limit one config per provider per tenant
            $table->unique(['tenant_id', 'provider']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('landlord')->dropIfExists('tenant_llm_configs');
    }
};
