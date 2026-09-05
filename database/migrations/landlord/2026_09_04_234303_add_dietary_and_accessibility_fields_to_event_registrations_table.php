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
            $table->string('dietary_requirements')->nullable()->after('phone');
            $table->string('accessibility_needs')->nullable()->after('dietary_requirements');
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->table('event_registrations', function (Blueprint $table): void {
            $table->dropColumn(['dietary_requirements', 'accessibility_needs']);
        });
    }
};
