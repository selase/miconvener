<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('landlord')->create('event_certificate_templates', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('event_id')->constrained('events')->cascadeOnDelete();

            $table->string('role')->default('delegate'); // delegate, speaker, presenter, volunteer, custom
            $table->string('title')->default('Certificate of Participation');
            $table->text('body_template')->nullable();
            $table->string('issuer_name')->nullable();
            $table->string('issuer_title')->nullable();
            $table->string('signature_path')->nullable();
            $table->string('background_path')->nullable();

            $table->boolean('show_qr')->default(true);
            $table->boolean('show_cpd_hours')->default(false);
            $table->decimal('default_cpd_hours', 4, 1)->default(0.0);

            $table->timestamps();

            $table->unique(['event_id', 'role']);
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->dropIfExists('event_certificate_templates');
    }
};
