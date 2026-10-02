<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('landlord')->table('store_listings', function (Blueprint $table): void {
            if (! Schema::connection('landlord')->hasColumn('store_listings', 'is_bookable')) {
                $table->boolean('is_bookable')->default(true)->after('rules_and_policies');
            }
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->table('store_listings', function (Blueprint $table): void {
            if (Schema::connection('landlord')->hasColumn('store_listings', 'is_bookable')) {
                $table->dropColumn('is_bookable');
            }
        });
    }
};
