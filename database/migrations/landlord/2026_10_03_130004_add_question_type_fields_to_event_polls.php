<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Room for the question types beyond multiple choice and free text.
     *
     * A poll's `settings` holds what one type needs and others do not -- a
     * scale's two ends, a number's unit. An answer that is a number, or a list
     * (several options picked, options put in order, a few words), has nowhere
     * to go in option_id or response_text, so it gets its own column each.
     */
    public function up(): void
    {
        Schema::connection('landlord')->table('event_polls', function (Blueprint $table): void {
            $table->jsonb('settings')->nullable()->after('requires_moderation');
        });

        Schema::connection('landlord')->table('event_poll_responses', function (Blueprint $table): void {
            $table->decimal('response_number', 16, 4)->nullable()->after('response_text');
            $table->jsonb('response_payload')->nullable()->after('response_number');
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->table('event_poll_responses', function (Blueprint $table): void {
            $table->dropColumn(['response_number', 'response_payload']);
        });

        Schema::connection('landlord')->table('event_polls', function (Blueprint $table): void {
            $table->dropColumn('settings');
        });
    }
};
