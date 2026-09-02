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
        Schema::connection('landlord')->create('llm_token_usages', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('uuid')->unique();

            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->onDelete('cascade');
            $table->foreignId('user_id')->nullable()->index()->constrained('users')->onDelete('set null');

            // Link to the API Key used (if any)
            $table->foreignUuid('api_key_id')->nullable()->index()->constrained('tenant_api_keys')->onDelete('set null');

            $table->string('provider'); // openai, anthropic, etc.
            $table->string('model'); // gpt-4, claude-3-opus

            $table->unsignedBigInteger('prompt_tokens')->default(0);
            $table->unsignedBigInteger('completion_tokens')->default(0);
            $table->unsignedBigInteger('total_tokens')->default(0);

            $table->decimal('cost_usd', 10, 6)->default(0); // Store cost with high precision

            $table->jsonb('context')->nullable(); // Metadata about the request (endpoint, feature used)
            $table->ipAddress('ip_address')->nullable();

            $table->string('request_hash')->nullable()->index(); // For de-duplication if needed

            $table->timestamps();

            // Indexes for Analytics
            $table->index(['tenant_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('landlord')->dropIfExists('llm_token_usages');
    }
};
