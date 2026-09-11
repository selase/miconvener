<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('landlord')->create('event_participant_group_members', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('event_id')->constrained('events')->cascadeOnDelete();
            $table->foreignUuid('group_id')->constrained('event_participant_groups')->cascadeOnDelete();
            $table->foreignUuid('registration_id')->constrained('event_registrations')->cascadeOnDelete();

            $table->boolean('is_manual')->default(false); // Manually pinned vs dynamically matched
            $table->dateTime('matched_at');

            $table->timestamps();

            $table->unique(['group_id', 'registration_id']);
            $table->index(['event_id', 'group_id']);
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->dropIfExists('event_participant_group_members');
    }
};
