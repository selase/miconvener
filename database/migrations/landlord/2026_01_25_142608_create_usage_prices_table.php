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
        Schema::connection('landlord')->create('usage_prices', function (Blueprint $table): void {
            $table->id();
            $table->string('target_type'); // App\Models\Package or App\Models\Tenant
            $table->string('target_id');
            $table->string('metric'); // UsageMetric enum value
            $table->decimal('unit_price', 20, 6)->default(0);
            $table->decimal('unit_quantity', 20, 4)->default(1); // e.g., price per 1000 units
            $table->string('currency', 3)->default('USD');
            $table->timestamps();

            $table->unique(['target_type', 'target_id', 'metric']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('landlord')->dropIfExists('usage_prices');
    }
};
