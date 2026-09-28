<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Two reads carry a live poll, and the table's only index starts with
     * poll_id to enforce uniqueness on respondent_token, which serves neither.
     *
     * The grouped count filters on is_approved before grouping on option_id, so
     * it goes in the middle; without it the index still needs a heap fetch per
     * row to check approval. The open-text panel orders by created_at and takes
     * thirty, which wants its own.
     */
    public function up(): void
    {
        Schema::connection('landlord')->table('event_poll_responses', function (Blueprint $table): void {
            $table->index(['poll_id', 'is_approved', 'option_id']);
            $table->index(['poll_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->table('event_poll_responses', function (Blueprint $table): void {
            $table->dropIndex(['poll_id', 'is_approved', 'option_id']);
            $table->dropIndex(['poll_id', 'created_at']);
        });
    }
};
