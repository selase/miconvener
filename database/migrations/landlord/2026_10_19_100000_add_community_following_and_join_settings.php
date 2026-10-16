<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('landlord')->create('community_followers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('channel', 8);
            $table->text('address');
            $table->string('address_hash', 64);
            $table->timestampTz('consented_at');
            $table->string('consent_source', 20)->default('organisation');
            $table->foreignUuid('source_event_id')->nullable()->constrained('events')->nullOnDelete();
            $table->timestampTz('unfollowed_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'channel', 'address_hash']);
        });
        Schema::connection('landlord')->table('community_settings', function (Blueprint $table): void {
            $table->string('join_mode', 24)->default('approval_required');
            $table->boolean('public_join_enabled')->default(false);
            $table->foreignUuid('public_membership_type_id')->nullable()->constrained('membership_types')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->table('community_settings', function (Blueprint $table): void {
            $table->dropForeign(['public_membership_type_id']);
            $table->dropColumn(['join_mode', 'public_join_enabled', 'public_membership_type_id']);
        });
        Schema::connection('landlord')->dropIfExists('community_followers');
    }
};
