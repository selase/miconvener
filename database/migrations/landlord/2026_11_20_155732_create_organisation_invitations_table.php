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
        Schema::connection('landlord')->create('organisation_invitations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('email');
            $table->foreignId('role_id')->constrained('roles')->restrictOnDelete();
            $table->json('event_ids');
            $table->string('status', 20)->default('pending')->index();
            $table->timestampTz('expires_at');
            $table->string('token_hash', 64)->nullable()->unique();
            $table->foreignId('invited_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('invited_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('invited_security_version')->nullable();
            $table->string('pending_email')->nullable();
            $table->unique(['tenant_id', 'pending_email']);
            $table->unsignedInteger('revision')->default(1);
            $table->text('delivery_payload')->nullable();
            $table->string('delivery_status', 20)->default('pending');
            $table->timestampTz('delivery_claimed_at')->nullable();
            $table->timestampTz('delivered_at')->nullable();
            $table->timestampTz('accepted_at')->nullable();
            $table->foreignId('accepted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
            $table->index(['tenant_id', 'status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->dropIfExists('organisation_invitations');
    }
};
