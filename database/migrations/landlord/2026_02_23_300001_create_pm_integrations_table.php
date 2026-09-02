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
        Schema::connection('landlord')->create('pm_integrations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('uuid')->unique();

            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->unsignedBigInteger('user_id')->index();

            $table->string('provider', 20);
            $table->string('auth_type', 10)->default('oauth');

            // OAuth tokens (nullable for API key providers)
            $table->text('access_token_encrypted')->nullable();
            $table->text('refresh_token_encrypted')->nullable();
            $table->timestamp('token_expires_at')->nullable();

            // API key (for Asana, Linear)
            $table->text('api_key_encrypted')->nullable();

            // External account info
            $table->string('external_email')->nullable();
            $table->string('external_account_id')->nullable();
            $table->string('external_account_name')->nullable();

            // Project/board selection
            $table->string('project_id')->nullable();
            $table->string('project_name')->nullable();
            $table->string('board_id')->nullable();
            $table->string('board_name')->nullable();

            // Settings
            $table->boolean('is_active')->default(true);
            $table->boolean('auto_push')->default(true);
            $table->jsonb('settings')->nullable();
            $table->jsonb('status_mapping')->nullable();

            // Sync tracking
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamp('last_error_at')->nullable();
            $table->text('last_error_message')->nullable();
            $table->string('webhook_secret', 64)->nullable();

            $table->timestamps();

            $table->unique(['tenant_id', 'user_id', 'provider']);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('landlord')->dropIfExists('pm_integrations');
    }
};
