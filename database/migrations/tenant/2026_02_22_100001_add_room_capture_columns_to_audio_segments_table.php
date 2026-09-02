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
        Schema::table('audio_segments', function (Blueprint $table): void {
            $table->string('source_type', 20)->default('participant');
            $table->index('source_type');
        });

        // Make participant_id nullable — drop FK + column together for SQLite compatibility
        Schema::table('audio_segments', function (Blueprint $table): void {
            $table->dropForeign(['participant_id']);
            $table->dropColumn('participant_id');
        });

        Schema::table('audio_segments', function (Blueprint $table): void {
            $table->foreignUuid('participant_id')
                ->nullable()
                ->constrained('meeting_participants')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('audio_segments', function (Blueprint $table): void {
            $table->dropIndex(['source_type']);
            $table->dropColumn('source_type');
        });

        Schema::table('audio_segments', function (Blueprint $table): void {
            $table->dropForeign(['participant_id']);
            $table->dropColumn('participant_id');
        });

        Schema::table('audio_segments', function (Blueprint $table): void {
            $table->foreignUuid('participant_id')
                ->constrained('meeting_participants');
        });
    }
};
