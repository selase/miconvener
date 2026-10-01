<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('landlord')->create('event_certificate_design_versions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('event_id')->constrained('events')->cascadeOnDelete();
            $table->foreignUuid('template_id')->constrained('event_certificate_templates')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('design_hash', 64);
            $table->string('design_mode')->default('miconvener');
            $table->string('orientation')->default('landscape');
            $table->string('page_size')->default('a4');
            $table->string('title');
            $table->text('body_template')->nullable();
            $table->string('issuer_name')->nullable();
            $table->string('issuer_title')->nullable();
            $table->string('signature_disk')->nullable();
            $table->string('signature_path')->nullable();
            $table->string('background_disk')->nullable();
            $table->string('background_path')->nullable();
            $table->boolean('show_qr')->default(true);
            $table->boolean('show_cpd_hours')->default(false);
            $table->decimal('default_cpd_hours', 4, 1)->default(0.0);
            $table->json('layout')->nullable();
            $table->timestamps();

            $table->unique(['template_id', 'version']);
            $table->unique(['template_id', 'design_hash']);
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->dropIfExists('event_certificate_design_versions');
    }
};
