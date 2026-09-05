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
            $table->decimal('platform_fee_percentage', 5, 2)->nullable()->after('status');
        });

        Schema::connection('landlord')->table('events', function (Blueprint $table): void {
            $table->decimal('platform_fee_percentage', 5, 2)->nullable()->after('currency');
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->table('tenants', function (Blueprint $table): void {
            $table->dropColumn('platform_fee_percentage');
        });

        Schema::connection('landlord')->table('events', function (Blueprint $table): void {
            $table->dropColumn('platform_fee_percentage');
        });
    }
};
