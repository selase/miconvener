<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The data-export feature never worked. BuildDataExportJob was dispatched from
 * nowhere, and the service it called -- App\Services\Compliance\DataExportService
 * -- does not exist in this repository, so the job would have fatally errored
 * had anything ever queued it. Production held no rows.
 *
 * The model, job, enum, notification and factory are removed with this. If
 * exports are built for real, they should be designed against the compliance
 * rules that apply then rather than resurrected from a shell that never ran.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('landlord')->dropIfExists('data_export_requests');
    }

    public function down(): void
    {
        Schema::connection('landlord')->create('data_export_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('uuid')->unique();

            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->uuid('requested_by');
            $table->string('type', 30);
            $table->jsonb('scope')->nullable();
            $table->string('status', 20)->default('pending');
            $table->string('file_path')->nullable();
            $table->bigInteger('file_size_bytes')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });
    }
};
