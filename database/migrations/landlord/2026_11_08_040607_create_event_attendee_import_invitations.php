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
        Schema::connection('landlord')->create('event_attendee_import_invite_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('import_id')->constrained('event_attendee_imports')->cascadeOnDelete();
            $table->uuid('operation_key');
            $table->string('selection_digest', 64);
            $table->timestamps();
            $table->unique(['import_id', 'operation_key']);
        });
        Schema::connection('landlord')->create('event_attendee_import_invitations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('import_id')->constrained('event_attendee_imports')->cascadeOnDelete();
            $table->foreignUuid('registration_id')->constrained('event_registrations')->cascadeOnDelete();
            $table->text('recipient');
            $table->string('status')->default('pending')->index();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->string('message')->nullable();
            $table->timestamps();
            $table->unique(['import_id', 'registration_id']);
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->dropIfExists('event_attendee_import_invitations');
        Schema::connection('landlord')->dropIfExists('event_attendee_import_invite_runs');
    }
};
