<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('landlord')->create('message_outcome_resolutions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('operator_reference');
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source_kind', 30);
            $table->uuid('source_id');
            $table->string('outcome', 30);
            $table->text('evidence');
            $table->text('reason');
            $table->jsonb('old_state');
            $table->jsonb('new_state');
            $table->timestamps();
            $table->unique(['tenant_id', 'source_kind', 'source_id'], 'message_outcome_resolution_unique');
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->dropIfExists('message_outcome_resolutions');
    }
};
