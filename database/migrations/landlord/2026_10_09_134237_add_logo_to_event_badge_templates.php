<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('landlord')->table('event_badge_templates', function (Blueprint $table): void {
            $table->string('logo_disk')->nullable();
            $table->string('logo_path')->nullable();
            $table->string('logo_source')->default('organization');
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->table('event_badge_templates', function (Blueprint $table): void {
            $table->dropColumn(['logo_disk', 'logo_path', 'logo_source']);
        });
    }
};
