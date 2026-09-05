<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('landlord')->table('tenants', function (Blueprint $table): void {
            $table->string('settlement_mode')->default('platform_default')->after('platform_fee_percentage');
        });

        // Every tenant that exists at the moment this migration runs is, by
        // definition, pre-existing — only own_gateway (today's only real
        // behavior before this feature shipped) is correct for them. The
        // column's 'platform_default' default (set above) only takes effect
        // for tenants created AFTER this migration runs, which is exactly
        // the intended new-tenant behavior. Folding the backfill into the
        // same migration (rather than a separate command run after deploy)
        // closes the mis-routing window entirely — there is no moment where
        // an existing tenant is platform_default.
        DB::connection('landlord')->table('tenants')->update(['settlement_mode' => 'own_gateway']);
    }

    public function down(): void
    {
        Schema::connection('landlord')->table('tenants', function (Blueprint $table): void {
            $table->dropColumn('settlement_mode');
        });
    }
};
