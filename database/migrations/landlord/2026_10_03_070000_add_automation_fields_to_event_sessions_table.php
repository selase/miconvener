<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::connection('landlord')->table('event_sessions', function (Blueprint $table): void {
            $table->string('cancellation_reason')->nullable()->after('occurrence_status');
            $table->timestamp('reminder_sent_at')->nullable()->after('presentation_url');
            $table->index(['event_id', 'is_occurrence', 'occurrence_status'], 'idx_sessions_event_occ_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('landlord')->table('event_sessions', function (Blueprint $table): void {
            $table->dropIndex('idx_sessions_event_occ_status');
            $table->dropColumn(['cancellation_reason', 'reminder_sent_at']);
        });
    }
};
