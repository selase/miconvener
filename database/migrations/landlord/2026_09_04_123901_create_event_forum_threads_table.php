<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('landlord')->create('event_forum_threads', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('event_id')->constrained('events')->cascadeOnDelete();
            $table->foreignUuid('session_id')->nullable()->constrained('event_sessions')->nullOnDelete();

            $table->string('title');
            $table->text('body');
            $table->string('author_name');
            $table->string('author_email')->nullable();

            $table->boolean('is_pinned')->default(false);
            $table->boolean('is_hidden')->default(false);
            $table->boolean('is_answered')->default(false);

            $table->timestamps();

            $table->index(['event_id', 'is_hidden', 'is_pinned']);
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->dropIfExists('event_forum_threads');
    }
};
