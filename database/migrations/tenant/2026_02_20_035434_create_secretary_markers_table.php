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
        Schema::create('secretary_markers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('meeting_id')->constrained('meetings')->cascadeOnDelete();
            $table->foreignUuid('participant_id')->constrained('meeting_participants');
            $table->string('marker_type', 20);
            $table->string('label')->nullable();
            $table->text('description')->nullable();
            $table->timestamp('placed_at');
            $table->foreignUuid('transcript_segment_id')->nullable()->constrained('transcript_segments');
            $table->timestamps();

            $table->index(['meeting_id', 'marker_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('secretary_markers');
    }
};
