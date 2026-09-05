<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('landlord')->create('event_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('event_id')->constrained('events')->cascadeOnDelete();

            $table->string('title');
            $table->text('description')->nullable();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->string('location')->nullable(); // room name, or "Virtual"
            $table->string('track')->nullable();
            $table->string('type')->default('session'); // keynote, plenary, workshop, breakout, panel, break, networking, session
            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();

            $table->index(['event_id', 'starts_at']);
            $table->index(['event_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->dropIfExists('event_sessions');
    }
};
