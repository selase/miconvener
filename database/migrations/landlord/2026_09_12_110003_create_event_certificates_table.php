<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('landlord')->create('event_certificates', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('uuid')->unique(); // Public verification UUID

            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('event_id')->constrained('events')->cascadeOnDelete();
            $table->foreignUuid('registration_id')->nullable()->constrained('event_registrations')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('template_id')->nullable()->constrained('event_certificate_templates')->nullOnDelete();

            $table->string('recipient_name');
            $table->string('recipient_email');
            $table->string('role')->default('delegate'); // delegate, speaker, presenter, volunteer, custom
            $table->decimal('cpd_hours', 4, 1)->default(0.0);
            $table->string('verification_code')->unique(); // e.g. MC-CERT-948271

            $table->dateTime('issued_at');
            $table->unsignedInteger('download_count')->default(0);

            $table->timestamps();

            $table->index(['event_id', 'role']);
            $table->index(['tenant_id', 'recipient_email']);
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->dropIfExists('event_certificates');
    }
};
