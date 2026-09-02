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
        Schema::create('minutes_comments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('meeting_minutes_id')
                ->constrained('meeting_minutes')
                ->cascadeOnDelete();
            $table->unsignedBigInteger('user_id');
            $table->text('body');
            $table->boolean('resolved')->default(false);
            $table->timestamps();

            $table->index('meeting_minutes_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('minutes_comments');
    }
};
