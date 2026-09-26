<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('landlord')->create('poll_decks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('event_id')->constrained('events')->cascadeOnDelete();
            $table->foreignUuid('session_id')->nullable()->constrained('event_sessions')->nullOnDelete();
            $table->string('title');
            $table->string('join_code', 8)->nullable()->unique();
            $table->string('status', 16)->default('draft');
            $table->foreignUuid('current_poll_id')->nullable()->constrained('event_polls')->nullOnDelete();
            $table->string('present_token', 64)->nullable()->unique();
            $table->timestamps();

            $table->index(['event_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->dropIfExists('poll_decks');
    }
};
