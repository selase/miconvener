<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An organiser's own domain, registered with SES so their event mail can come
 * from their address. Set up by a superadmin (Enterprise only); mail uses it
 * only while SES reports the domain verified.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('landlord')->create('tenant_sending_domains', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->unique()->constrained('tenants')->cascadeOnDelete();
            $table->string('domain')->unique();
            $table->string('from_address');
            $table->string('status', 20)->default('pending');
            $table->json('dkim_records')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->dropIfExists('tenant_sending_domains');
    }
};
