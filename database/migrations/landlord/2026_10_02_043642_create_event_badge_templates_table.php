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
        Schema::connection('landlord')->create('event_badge_templates', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('event_id')->unique()->constrained('events')->cascadeOnDelete();
            $table->decimal('width_mm', 6, 2)->default(100);
            $table->decimal('height_mm', 6, 2)->default(70);
            $table->string('orientation')->default('landscape');
            $table->string('background_disk')->nullable();
            $table->string('background_path')->nullable();
            $table->json('layout')->nullable();
            $table->json('tier_styles')->nullable();
            $table->json('sheet_settings')->nullable();
            $table->unsignedInteger('design_version')->default(1);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('landlord')->dropIfExists('event_badge_templates');
    }
};
