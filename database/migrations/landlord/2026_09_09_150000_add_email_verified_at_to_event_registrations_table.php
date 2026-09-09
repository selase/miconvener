<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A free registration costs nothing and proves nothing: anyone can type any
     * address and receive a valid ticket at it. Verification closes that, and is
     * recorded here rather than inferred, so an unverified free ticket can be
     * told apart from a paid one.
     *
     * Existing registrations are backfilled as verified. They were issued under
     * the old rules and their holders should not arrive at a door to find their
     * ticket retroactively invalid.
     */
    public function up(): void
    {
        Schema::connection('landlord')->table('event_registrations', function (Blueprint $table): void {
            $table->timestamp('email_verified_at')->nullable()->after('email');
        });

        Schema::connection('landlord')->getConnection()
            ->table('event_registrations')
            ->whereNotNull('ticket_code')
            ->update(['email_verified_at' => now()]);
    }

    public function down(): void
    {
        Schema::connection('landlord')->table('event_registrations', function (Blueprint $table): void {
            $table->dropColumn('email_verified_at');
        });
    }
};
