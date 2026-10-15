<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('landlord')->create('community_settings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('timezone')->default('Africa/Accra');
            $table->unsignedTinyInteger('week_starts_on')->default(1);
            $table->unsignedTinyInteger('financial_year_start_month')->default(1);
            $table->unsignedInteger('generate_days_ahead')->default(7);
            $table->boolean('charge_current_period_on_join')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->dropIfExists('community_settings');
    }
};
