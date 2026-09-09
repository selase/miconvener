<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Some events cannot show their lineup to the world. A closed corporate
     * day's speaker list is itself the confidential part, and until now the
     * public page rendered speakers, sessions and sponsors to anyone holding
     * the link, before they had registered for anything.
     *
     * Existing events default to public, which is what they already were.
     */
    public function up(): void
    {
        Schema::connection('landlord')->table('events', function (Blueprint $table): void {
            $table->string('visibility', 16)->default('public')->after('status');
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->table('events', function (Blueprint $table): void {
            $table->dropColumn('visibility');
        });
    }
};
