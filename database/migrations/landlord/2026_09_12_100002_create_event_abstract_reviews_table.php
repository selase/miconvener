<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('landlord')->create('event_abstract_reviews', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('abstract_id')->constrained('event_abstracts')->cascadeOnDelete();
            $table->foreignId('reviewer_id')->constrained('users')->cascadeOnDelete();

            $table->string('status')->default('pending'); // pending, completed, declined

            $table->unsignedTinyInteger('novelty_score')->nullable(); // 1 - 5
            $table->unsignedTinyInteger('methodology_score')->nullable(); // 1 - 5
            $table->unsignedTinyInteger('relevance_score')->nullable(); // 1 - 5
            $table->unsignedTinyInteger('clarity_score')->nullable(); // 1 - 5
            $table->decimal('total_score', 4, 2)->nullable(); // average score e.g. 4.25

            $table->string('recommendation')->nullable(); // accept_oral, accept_poster, reject
            $table->text('comments_to_author')->nullable();
            $table->text('confidential_comments')->nullable(); // to committee/chair
            $table->dateTime('completed_at')->nullable();

            $table->timestamps();

            $table->unique(['abstract_id', 'reviewer_id']);
            $table->index(['tenant_id', 'reviewer_id']);
            $table->index(['abstract_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->dropIfExists('event_abstract_reviews');
    }
};
