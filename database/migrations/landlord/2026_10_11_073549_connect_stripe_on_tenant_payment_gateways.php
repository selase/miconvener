<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A Stripe gateway is now a Connect link to the tenant's own account, so it
     * holds an account id rather than a pasted secret key.
     */
    public function up(): void
    {
        Schema::connection('landlord')->table('tenant_payment_gateways', function (Blueprint $table): void {
            $table->text('api_key_encrypted')->nullable()->change();
            $table->string('provider_account_id')->nullable()->after('provider')->index();
        });

        // A pasted Stripe key was never used to take a ticket payment, and
        // without a Connect link it cannot carry the platform fee, so it must
        // not read as a working gateway.
        DB::connection('landlord')->table('tenant_payment_gateways')
            ->where('provider', 'stripe')
            ->whereNull('provider_account_id')
            ->update(['is_active' => false]);
    }

    public function down(): void
    {
        DB::connection('landlord')->table('tenant_payment_gateways')
            ->whereNull('api_key_encrypted')
            ->delete();

        Schema::connection('landlord')->table('tenant_payment_gateways', function (Blueprint $table): void {
            $table->dropIndex(['provider_account_id']);
            $table->dropColumn('provider_account_id');
            $table->text('api_key_encrypted')->nullable(false)->change();
        });
    }
};
