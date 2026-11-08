<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'landlord';

    public function up(): void
    {
        Schema::connection('landlord')->create('event_attendee_imports', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source_namespace', 100);
            $table->string('file_digest', 64);
            $table->string('status')->default('preview');
            $table->string('operation_key', 100)->nullable();
            $table->string('selection_digest', 64)->nullable();
            $table->text('message')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'event_id', 'operation_key']);
        });
        Schema::connection('landlord')->create('event_attendee_import_rows', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('import_id')->constrained('event_attendee_imports')->cascadeOnDelete();
            $table->unsignedInteger('line');
            $table->text('data')->nullable();
            $table->jsonb('errors')->nullable();
            $table->string('fingerprint', 64);
            $table->string('status');
            $table->boolean('selected')->default(false);
            $table->foreignUuid('registration_id')->nullable()->constrained('event_registrations')->nullOnDelete();
            $table->timestamps();
            $table->unique(['import_id', 'line']);
            $table->index(['import_id', 'selected', 'status']);
        });
        Schema::connection('landlord')->table('event_registrations', function (Blueprint $table): void {
            $table->foreignUuid('attendee_import_id')->nullable()->constrained('event_attendee_imports')->nullOnDelete();
            $table->string('registration_source')->default('native');
            $table->string('external_source', 100)->nullable();
            $table->string('external_reference', 200)->nullable();
            $table->string('admission_payment_source')->nullable();
            $table->bigInteger('external_amount')->nullable();
            $table->string('external_currency', 3)->nullable();
            $table->unique(['event_id', 'external_source', 'external_reference'], 'event_external_admission_unique');
        });
        Schema::connection('landlord')->create('event_registration_quota_locks', function (Blueprint $table): void {
            $table->foreignUuid('tenant_id')->primary()->constrained()->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->dropIfExists('event_registration_quota_locks');
        Schema::connection('landlord')->table('event_registrations', function (Blueprint $table): void {
            $table->dropUnique('event_external_admission_unique');
            $table->dropConstrainedForeignId('attendee_import_id');
            $table->dropColumn(['registration_source', 'external_source', 'external_reference', 'admission_payment_source', 'external_amount', 'external_currency']);
        });
        Schema::connection('landlord')->dropIfExists('event_attendee_import_rows');
        Schema::connection('landlord')->dropIfExists('event_attendee_imports');
    }
};
