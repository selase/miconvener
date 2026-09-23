<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One-time codes proving someone controls an address across the platform.
 * Landlord table dedicated to platform proof, with no tenant_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('landlord')->create('platform_attendee_access_codes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('email_normalized');
            $table->string('code_hash');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();

            $table->index(['email_normalized', 'consumed_at', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->dropIfExists('platform_attendee_access_codes');
    }
};
