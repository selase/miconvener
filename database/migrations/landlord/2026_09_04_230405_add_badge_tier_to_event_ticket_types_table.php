<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('landlord')->table('event_ticket_types', function (Blueprint $table): void {
            $table->string('badge_tier')->default('general')->after('name');
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->table('event_ticket_types', function (Blueprint $table): void {
            $table->dropColumn('badge_tier');
        });
    }
};
