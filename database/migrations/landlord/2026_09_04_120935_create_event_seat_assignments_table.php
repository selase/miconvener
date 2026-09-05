<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('landlord')->create('event_seat_assignments', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('event_id')->constrained('events')->cascadeOnDelete();
            $table->foreignUuid('room_id')->constrained('event_venue_rooms')->cascadeOnDelete();
            $table->foreignUuid('registration_id')->constrained('event_registrations')->cascadeOnDelete();

            $table->string('seat_label'); // e.g. "A-01"

            $table->timestamps();

            $table->unique(['room_id', 'seat_label']);
            $table->unique('registration_id');
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->dropIfExists('event_seat_assignments');
    }
};
