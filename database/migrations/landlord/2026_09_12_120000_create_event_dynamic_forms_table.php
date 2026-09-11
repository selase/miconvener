<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('landlord')->create('event_dynamic_forms', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('event_id')->constrained('events')->cascadeOnDelete();

            $table->string('title');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->string('type')->default('general'); // registration, survey, feedback, cme_evaluation, workshop_signup, abstract_disclosure, general

            $table->boolean('is_active')->default(true);
            $table->boolean('is_public')->default(true);
            $table->boolean('requires_check_in')->default(false);

            $table->json('schema')->nullable(); // Array of field definition objects

            $table->dateTime('starts_at')->nullable();
            $table->dateTime('ends_at')->nullable();
            $table->unsignedInteger('submission_limit')->nullable();

            $table->timestamps();

            $table->unique(['event_id', 'slug']);
            $table->index(['event_id', 'type']);
            $table->index(['event_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->dropIfExists('event_dynamic_forms');
    }
};
