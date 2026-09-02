<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('floor_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('meeting_id')->constrained('meetings')->cascadeOnDelete();
            $table->foreignUuid('participant_id')->constrained('meeting_participants')->cascadeOnDelete();
            $table->string('status', 20)->default('pending');
            $table->integer('priority')->default(0);
            $table->timestamp('requested_at');
            $table->timestamp('granted_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['meeting_id', 'status']);
            $table->index(['meeting_id', 'priority', 'requested_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('floor_requests');
    }
};
