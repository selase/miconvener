<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('landlord')->create('event_abstracts', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('event_id')->constrained('events')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('code')->unique(); // e.g. ABS-2026-0001
            $table->string('title');
            $table->string('track')->nullable(); // topic category e.g. "Oncology", "Health Informatics"
            $table->string('presentation_preference')->default('either'); // oral, poster, either
            $table->json('structured_abstract')->nullable(); // background, methods, results, conclusion
            $table->text('body')->nullable();
            $table->json('keywords')->nullable();
            $table->text('conflict_of_interest')->nullable();
            $table->string('file_path')->nullable();

            $table->string('status')->default('submitted'); // submitted, under_review, accepted_oral, accepted_poster, rejected, withdrawn
            $table->text('decision_notes')->nullable();
            $table->dateTime('decided_at')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['event_id', 'status']);
            $table->index(['event_id', 'track']);
            $table->index(['tenant_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->dropIfExists('event_abstracts');
    }
};
