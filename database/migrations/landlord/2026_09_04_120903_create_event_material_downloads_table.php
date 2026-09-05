<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('landlord')->create('event_material_downloads', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('material_id')->constrained('event_materials')->cascadeOnDelete();
            $table->foreignUuid('registration_id')->constrained('event_registrations')->cascadeOnDelete();

            $table->string('ip_address')->nullable();
            $table->string('user_agent')->nullable();

            $table->timestamps();

            $table->index(['material_id', 'registration_id']);
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->dropIfExists('event_material_downloads');
    }
};
