<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('landlord')->create('community_message_preferences', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('channel', 10);
            $table->char('address_hash', 64);
            $table->text('address');
            $table->timestampTz('marketing_disabled_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'channel', 'address_hash'], 'community_preference_address_unique');
        });
        Schema::connection('landlord')->create('community_preference_tokens', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('preference_id')->constrained('community_message_preferences')->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->timestamps();
        });
        Schema::connection('landlord')->create('community_audience_addresses', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('run_id')->index();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('channel', 10);
            $table->char('address_hash', 64);
            $table->text('address');
            $table->timestampTz('expires_at')->index();
            $table->timestamps();
            $table->unique(['run_id', 'channel', 'address_hash'], 'community_audience_address_unique');
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->dropIfExists('community_audience_addresses');
        Schema::connection('landlord')->dropIfExists('community_preference_tokens');
        Schema::connection('landlord')->dropIfExists('community_message_preferences');
    }
};
