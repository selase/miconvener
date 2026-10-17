<?php

declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('landlord')->create('community_follower_channels', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->uuid('follower_id');
            $table->string('channel', 8)->default('sms');
            $table->text('address');
            $table->char('address_hash', 64);
            $table->timestampTz('consented_at');
            $table->timestampTz('disabled_at')->nullable();
            $table->timestamps();
            $table->foreign(['tenant_id', 'follower_id'])->references(['tenant_id', 'id'])->on('community_followers')->cascadeOnDelete();
            $table->unique(['follower_id', 'channel']);
            $table->index(['tenant_id', 'address_hash']);
        });
        Schema::connection('landlord')->getConnection()->statement("ALTER TABLE community_follower_channels ADD CONSTRAINT follower_sms_channel CHECK (channel='sms')");
    }

    public function down(): void
    {
        Schema::connection('landlord')->dropIfExists('community_follower_channels');
    }
};
