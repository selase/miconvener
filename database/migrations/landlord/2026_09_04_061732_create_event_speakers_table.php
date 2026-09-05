<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('landlord')->create('event_speakers', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('event_id')->constrained('events')->cascadeOnDelete();
            $table->foreignUuid('speaker_id')->constrained('speakers')->cascadeOnDelete();

            $table->string('role')->default('speaker'); // speaker, chair, moderator, panelist
            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();

            $table->unique(['event_id', 'speaker_id']);
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->dropIfExists('event_speakers');
    }
};
