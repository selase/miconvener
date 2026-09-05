<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('landlord')->create('event_forum_reports', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('thread_id')->constrained('event_forum_threads')->cascadeOnDelete();

            $table->string('reason')->nullable();
            $table->string('reporter_token');

            $table->timestamps();

            $table->unique(['thread_id', 'reporter_token']);
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->dropIfExists('event_forum_reports');
    }
};
