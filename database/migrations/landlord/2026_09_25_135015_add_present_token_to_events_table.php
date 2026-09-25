<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets the machine driving the projector open the results screen without
 * anybody's console password on it, and lets an organiser revoke that link by
 * issuing a new one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('landlord')->table('events', function (Blueprint $table): void {
            $table->string('present_token', 64)->nullable()->unique()->after('slug');
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->table('events', function (Blueprint $table): void {
            $table->dropColumn('present_token');
        });
    }
};
