<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Addresses are stored exactly as typed, so Ama@Example.com and
 * ama@example.com are the same attendee but not the same string. Lookups
 * compare lower(email); this index keeps that from scanning the table.
 * Stored addresses are deliberately left as typed rather than rewritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('landlord')->table('event_registrations', function (Blueprint $table): void {
            $table->rawIndex('tenant_id, lower(email)', 'event_registrations_tenant_id_lower_email_index');
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->table('event_registrations', function (Blueprint $table): void {
            $table->dropIndex('event_registrations_tenant_id_lower_email_index');
        });
    }
};
