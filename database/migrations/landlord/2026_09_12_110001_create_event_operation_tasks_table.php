<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('landlord')->create('event_operation_tasks', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('event_id')->constrained('events')->cascadeOnDelete();
            $table->foreignUuid('pillar_id')->constrained('event_operation_pillars')->cascadeOnDelete();

            $table->string('title');
            $table->text('description')->nullable();

            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('owner_name')->nullable();

            $table->date('due_date')->nullable();
            $table->string('priority')->default('medium'); // low, medium, high, urgent
            $table->string('status')->default('not_started'); // not_started, in_progress, blocked, done

            $table->uuid('dependency_task_id')->nullable()->index();

            $table->bigInteger('estimated_budget')->default(0); // minor units
            $table->bigInteger('actual_budget')->default(0); // minor units

            $table->dateTime('completed_at')->nullable();
            $table->timestamps();

            $table->index(['event_id', 'pillar_id']);
            $table->index(['event_id', 'status']);
            $table->index(['event_id', 'due_date']);
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->dropIfExists('event_operation_tasks');
    }
};
