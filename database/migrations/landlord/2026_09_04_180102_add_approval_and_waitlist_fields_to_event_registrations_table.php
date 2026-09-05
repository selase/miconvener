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
            $table->unsignedInteger('waitlist_position')->nullable()->after('status');
            $table->string('approval_note')->nullable()->after('waitlist_position');
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->table('event_registrations', function (Blueprint $table): void {
            $table->dropColumn(['waitlist_position', 'approval_note']);
        });
    }
};
