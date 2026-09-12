<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('landlord')->table('event_registrations', function (Blueprint $table): void {
            $table->unsignedBigInteger('charged_amount')->nullable()->after('platform_fee_amount');
            $table->unsignedBigInteger('gateway_fee_amount')->nullable()->after('charged_amount');
        });

        Schema::connection('landlord')->table('event_payouts', function (Blueprint $table): void {
            $table->unsignedBigInteger('transfer_fee_amount')->default(0)->after('amount');
            $table->unsignedBigInteger('net_paid_amount')->nullable()->after('transfer_fee_amount');
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->table('event_registrations', function (Blueprint $table): void {
            $table->dropColumn(['charged_amount', 'gateway_fee_amount']);
        });

        Schema::connection('landlord')->table('event_payouts', function (Blueprint $table): void {
            $table->dropColumn(['transfer_fee_amount', 'net_paid_amount']);
        });
    }
};
