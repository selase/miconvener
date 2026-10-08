<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('landlord')->table('event_badge_prints', function (Blueprint $table): void {
            $table->uuid('export_reference')->nullable();
            $table->string('export_fingerprint', 64)->nullable();
            $table->unique(['event_id', 'export_reference', 'registration_id'], 'badge_export_registration_unique');
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->table('event_badge_prints', function (Blueprint $table): void {
            $table->dropUnique('badge_export_registration_unique');
            $table->dropColumn(['export_reference', 'export_fingerprint']);
        });
    }
};
