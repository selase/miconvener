<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('landlord')->table('tenants', function (Blueprint $table): void {
            $table->unsignedBigInteger('platform_fee_cap_amount')->nullable()->after('platform_fee_percentage');
            $table->string('fee_bearer')->nullable()->after('platform_fee_cap_amount');
        });

        Schema::connection('landlord')->table('events', function (Blueprint $table): void {
            $table->unsignedBigInteger('platform_fee_cap_amount')->nullable()->after('platform_fee_percentage');
            $table->string('fee_bearer')->nullable()->after('platform_fee_cap_amount');
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->table('tenants', function (Blueprint $table): void {
            $table->dropColumn(['platform_fee_cap_amount', 'fee_bearer']);
        });

        Schema::connection('landlord')->table('events', function (Blueprint $table): void {
            $table->dropColumn(['platform_fee_cap_amount', 'fee_bearer']);
        });
    }
};
