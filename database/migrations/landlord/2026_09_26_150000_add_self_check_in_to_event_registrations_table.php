<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('landlord')->table('event_registrations', function (Blueprint $table): void {
            // A door scan and a self report are both presence, and an organiser
            // reconciling a room needs to tell them apart.
            $table->string('checked_in_source', 16)->nullable()->after('checked_in_by');
        });

        Schema::connection('landlord')->table('events', function (Blueprint $table): void {
            $table->boolean('allows_self_check_in')->default(false)->after('location_type');
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->table('event_registrations', function (Blueprint $table): void {
            $table->dropColumn('checked_in_source');
        });

        Schema::connection('landlord')->table('events', function (Blueprint $table): void {
            $table->dropColumn('allows_self_check_in');
        });
    }
};
