<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('landlord')->create('chat_integrations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('uuid')->unique();

            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->unsignedBigInteger('user_id')->index();

            $table->string('provider', 20);
            $table->text('bot_token_encrypted')->nullable();
            $table->text('access_token_encrypted')->nullable();
            $table->text('refresh_token_encrypted')->nullable();
            $table->timestamp('token_expires_at')->nullable();

            $table->string('team_id')->nullable();
            $table->string('team_name')->nullable();
            $table->string('channel_id')->nullable();
            $table->string('channel_name')->nullable();
            $table->string('external_user_id')->nullable();

            $table->jsonb('notification_settings')->nullable();
            $table->boolean('is_active')->default(true);
            $table->jsonb('settings')->nullable();

            $table->timestamp('last_synced_at')->nullable();
            $table->timestamp('last_error_at')->nullable();
            $table->text('last_error_message')->nullable();

            $table->timestamps();

            $table->unique(['tenant_id', 'user_id', 'provider']);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->dropIfExists('chat_integrations');
    }
};
