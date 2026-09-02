<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discussion_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('meeting_id')->constrained()->cascadeOnDelete();
            $table->integer('version')->default(1);
            $table->string('status', 20)->default('draft');
            $table->string('generated_by', 20)->default('ai');
            $table->string('ai_model', 100)->nullable();
            $table->integer('ai_tokens_used')->nullable();
            $table->string('pdf_s3_key', 500)->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->unique(['meeting_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discussion_logs');
    }
};
