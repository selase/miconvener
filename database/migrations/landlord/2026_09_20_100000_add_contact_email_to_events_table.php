<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where replies to an event's mail should land, when they should not land in
 * the organization's general inbox. Optional: left empty, attendee mail replies
 * to tenants.email, which every tenant already has.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('landlord')->table('events', function (Blueprint $table): void {
            $table->string('contact_email')->nullable()->after('virtual_link');
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->table('events', function (Blueprint $table): void {
            $table->dropColumn('contact_email');
        });
    }
};
