<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('landlord')->create('event_venue_rooms', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('event_id')->constrained('events')->cascadeOnDelete();

            $table->string('name');
            $table->unsignedInteger('rows');
            $table->unsignedInteger('seats_per_row');
            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();

            $table->index(['event_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->dropIfExists('event_venue_rooms');
    }
};
