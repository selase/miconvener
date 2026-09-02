<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discussion_log_entries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('discussion_log_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('transcript_segment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('agenda_item_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('participant_id')->nullable()->constrained('meeting_participants')->nullOnDelete();
            $table->integer('sort_order')->default(0);
            $table->decimal('timestamp_seconds', 10, 3)->nullable();
            $table->string('speaker_name', 200);
            $table->text('statement');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['discussion_log_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discussion_log_entries');
    }
};
