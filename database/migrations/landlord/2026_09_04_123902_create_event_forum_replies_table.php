<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('landlord')->create('event_forum_replies', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('thread_id')->constrained('event_forum_threads')->cascadeOnDelete();
            $table->foreignId('host_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('author_name');
            $table->text('body');
            $table->string('tag')->nullable(); // official_answer, important, action_item, faq

            $table->timestamps();

            $table->index(['thread_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->dropIfExists('event_forum_replies');
    }
};
