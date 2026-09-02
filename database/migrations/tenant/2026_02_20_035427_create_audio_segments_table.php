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
        Schema::create('audio_segments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('meeting_id')->constrained('meetings')->cascadeOnDelete();
            $table->foreignUuid('participant_id')->constrained('meeting_participants');
            $table->string('s3_key', 500);
            $table->string('s3_bucket', 100);
            $table->integer('chunk_index');
            $table->decimal('duration_seconds', 8, 2)->nullable();
            $table->bigInteger('file_size_bytes')->nullable();
            $table->string('format', 20)->default('webm');
            $table->integer('sample_rate')->default(16000);
            $table->timestamp('recorded_at');
            $table->timestamp('uploaded_at')->nullable();
            $table->string('status', 20)->default('uploading');
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index(['meeting_id', 'recorded_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audio_segments');
    }
};
