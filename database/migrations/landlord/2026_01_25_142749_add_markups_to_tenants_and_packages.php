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
        Schema::connection('landlord')->table('packages', function (Blueprint $table): void {
            $table->decimal('markup_percentage', 5, 2)->default(0)->after('is_active');
        });

        Schema::connection('landlord')->table('tenants', function (Blueprint $table): void {
            $table->decimal('markup_percentage', 5, 2)->default(0)->after('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('landlord')->table('packages', function (Blueprint $table): void {
            $table->dropColumn('markup_percentage');
        });

        Schema::connection('landlord')->table('tenants', function (Blueprint $table): void {
            $table->dropColumn('markup_percentage');
        });
    }
};
