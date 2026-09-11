<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('landlord')->create('event_participant_groups', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('event_id')->constrained('events')->cascadeOnDelete();

            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->string('color')->default('#3B82F6');
            $table->string('icon')->default('users');
            $table->string('type')->default('dynamic'); // dynamic, manual

            $table->json('criteria')->nullable(); // Array of filter rule objects
            $table->unsignedInteger('member_count')->default(0);

            $table->timestamps();

            $table->unique(['event_id', 'slug']);
            $table->index(['event_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->dropIfExists('event_participant_groups');
    }
};
