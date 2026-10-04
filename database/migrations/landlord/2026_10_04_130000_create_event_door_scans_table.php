<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every door scan, live or synced from a phone that was offline. Entry is per
 * event day, so a multi-day event admits each guest once a day, and a ticket
 * admitted twice in one day can be listed afterwards.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('landlord')->create('event_door_scans', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('event_id')->constrained('events')->cascadeOnDelete();
            $table->foreignUuid('registration_id')->constrained('event_registrations')->cascadeOnDelete();
            $table->foreignUuid('staff_link_id')->nullable()->constrained('event_staff_links')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('client_scan_id', 64)->nullable()->unique();
            $table->date('event_day');
            $table->timestamp('scanned_at');
            $table->boolean('was_offline')->default(false);
            $table->string('outcome', 16);
            $table->timestamps();

            $table->index(['event_id', 'event_day', 'outcome']);
            $table->index(['registration_id', 'event_day']);
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->dropIfExists('event_door_scans');
    }
};
