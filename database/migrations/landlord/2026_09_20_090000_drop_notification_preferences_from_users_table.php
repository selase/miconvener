<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * users.notification_preferences was written by a Livewire screen at
 * /settings/notifications and read by nothing. No notification, mailer or
 * listener ever consulted it, so a user who set a preference there changed
 * nothing about the mail they received. The screen was removed once the
 * console moved to Inertia, leaving the column with no writer either.
 *
 * Anything stored here is therefore inert, and is dropped rather than carried
 * forward. If notification preferences are built for real, they should be
 * designed against the notifications that exist today.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('landlord')->table('users', function (Blueprint $table): void {
            $table->dropColumn('notification_preferences');
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->table('users', function (Blueprint $table): void {
            $table->jsonb('notification_preferences')->nullable()->after('status');
        });
    }
};
