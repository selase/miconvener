<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('landlord')->create('event_dynamic_form_submissions', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('event_id')->constrained('events')->cascadeOnDelete();
            $table->foreignUuid('form_id')->constrained('event_dynamic_forms')->cascadeOnDelete();
            $table->foreignUuid('registration_id')->nullable()->constrained('event_registrations')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('respondent_name')->nullable();
            $table->string('respondent_email')->nullable();
            $table->json('answers')->nullable(); // Key-value answers map

            $table->dateTime('submitted_at');
            $table->timestamps();

            $table->index(['form_id', 'submitted_at']);
            $table->index(['event_id', 'respondent_email']);
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->dropIfExists('event_dynamic_form_submissions');
    }
};
