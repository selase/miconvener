<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('landlord')->create('event_session_speakers', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuid('session_id')->constrained('event_sessions')->cascadeOnDelete();
            $table->foreignUuid('speaker_id')->constrained('speakers')->cascadeOnDelete();

            $table->string('role')->default('speaker');
            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();

            $table->unique(['session_id', 'speaker_id']);
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->dropIfExists('event_session_speakers');
    }
};
