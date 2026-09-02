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
        Schema::create('task_reminders', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('action_item_id')->constrained('action_items')->cascadeOnDelete();
            $table->unsignedBigInteger('user_id')->index();
            $table->timestamp('remind_at');
            $table->string('channel', 20)->default('email');
            $table->timestamp('sent_at')->nullable();
            $table->string('status', 20)->default('pending');
            $table->timestamps();

            $table->index(['remind_at', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('task_reminders');
    }
};
