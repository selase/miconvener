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
            $table->foreignUuid('ticket_type_id')->nullable()->after('event_id')
                ->constrained('event_ticket_types')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->table('event_registrations', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('ticket_type_id');
        });
    }
};
