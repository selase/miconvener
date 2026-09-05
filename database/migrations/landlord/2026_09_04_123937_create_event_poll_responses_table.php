<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('landlord')->create('event_poll_responses', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('poll_id')->constrained('event_polls')->cascadeOnDelete();
            $table->foreignUuid('option_id')->nullable()->constrained('event_poll_options')->cascadeOnDelete();

            $table->text('response_text')->nullable(); // for open questions
            $table->string('respondent_token', 64); // opaque per-browser token, dedupes without accounts

            $table->timestamps();

            $table->unique(['poll_id', 'respondent_token']);
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->dropIfExists('event_poll_responses');
    }
};
