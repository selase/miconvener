<?php

declare(strict_types=1);

use App\Services\Billing\ComplimentaryPlans;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a renewal needs to know about how a plan was paid, and which tenants
 * are never billed. Plans used to be one-off payments nothing ever ended.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('landlord')->table('subscriptions', function (Blueprint $table): void {
            $table->string('interval', 10)->nullable()->after('provider_plan');
            $table->text('authorization_code')->nullable();
            $table->boolean('authorization_reusable')->default(false);
            $table->string('authorization_email')->nullable();
            $table->string('authorization_label')->nullable();
            $table->timestamp('grace_ends_at')->nullable();
            $table->unsignedSmallInteger('renewal_attempts')->default(0);
        });

        Schema::connection('landlord')->table('tenants', function (Blueprint $table): void {
            $table->boolean('billing_complimentary')->default(false);
        });

        app(ComplimentaryPlans::class)->markHandProvisionedTenants();
    }

    public function down(): void
    {
        Schema::connection('landlord')->table('subscriptions', function (Blueprint $table): void {
            $table->dropColumn(['interval', 'authorization_code', 'authorization_reusable', 'authorization_email', 'authorization_label', 'grace_ends_at', 'renewal_attempts']);
        });

        Schema::connection('landlord')->table('tenants', function (Blueprint $table): void {
            $table->dropColumn('billing_complimentary');
        });
    }
};
