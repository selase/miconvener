<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Staff links: a named, revocable link a crew member opens on their phone to
 * scan tickets and answer attendee requests for one event, without an account.
 * Check-ins and claimed requests record which link handled them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('landlord')->create('event_staff_links', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('event_id')->constrained('events')->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('token', 64)->unique();
            $table->string('pin_hash')->nullable();
            $table->boolean('can_check_in')->default(true);
            $table->boolean('can_handle_requests')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'revoked_at']);
            $table->index(['event_id', 'revoked_at']);
        });

        Schema::connection('landlord')->table('event_registrations', function (Blueprint $table): void {
            $table->foreignUuid('checked_in_by_staff_link_id')->nullable()->after('checked_in_source')
                ->constrained('event_staff_links')->nullOnDelete();
        });

        Schema::connection('landlord')->table('event_service_requests', function (Blueprint $table): void {
            $table->foreignUuid('assigned_staff_link_id')->nullable()->after('assigned_to')
                ->constrained('event_staff_links')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->table('event_service_requests', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('assigned_staff_link_id');
        });

        Schema::connection('landlord')->table('event_registrations', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('checked_in_by_staff_link_id');
        });

        Schema::connection('landlord')->dropIfExists('event_staff_links');
    }
};
