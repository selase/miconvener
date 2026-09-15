<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An event that is already selling when its organizer's plan lapses or is
 * downgraded keeps running on the terms it started with. These columns record
 * that: when it was protected, and the commercial terms frozen at that moment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('landlord')->table('events', function (Blueprint $table): void {
            $table->timestamp('grandfathered_at')->nullable();
            $table->timestamp('terms_locked_at')->nullable();
            $table->decimal('locked_platform_fee_percentage', 5, 2)->nullable();
            $table->unsignedBigInteger('locked_platform_fee_cap_amount')->nullable();
            $table->string('locked_fee_bearer')->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->table('events', function (Blueprint $table): void {
            $table->dropColumn(['grandfathered_at', 'terms_locked_at', 'locked_platform_fee_percentage', 'locked_platform_fee_cap_amount', 'locked_fee_bearer']);
        });
    }
};
