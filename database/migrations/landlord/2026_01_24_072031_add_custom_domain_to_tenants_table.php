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
        Schema::table('tenants', function (Blueprint $table): void {
            $table->string('custom_domain')->nullable()->unique();
            $table->timestamp('custom_domain_verified_at')->nullable();
            $table->string('custom_domain_status')->default('pending'); // pending, active, failed
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropColumn(['custom_domain', 'custom_domain_verified_at', 'custom_domain_status']);
        });
    }
};
