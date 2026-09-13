<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('packages', function (Blueprint $table): void {
            $table->unsignedBigInteger('default_platform_fee_cap_amount')->nullable()->after('default_platform_fee_percentage');
            $table->string('default_fee_bearer')->nullable()->after('default_platform_fee_cap_amount');
        });
    }

    public function down(): void
    {
        Schema::table('packages', function (Blueprint $table): void {
            $table->dropColumn(['default_platform_fee_cap_amount', 'default_fee_bearer']);
        });
    }
};
