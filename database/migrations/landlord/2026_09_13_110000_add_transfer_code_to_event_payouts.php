<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('landlord')->table('event_payouts', function (Blueprint $table): void {
            // Paystack addresses a held transfer by its transfer code, which is
            // not the reference we generate, so finalising one needs both.
            $table->string('provider_transfer_code')->nullable()->after('provider_reference');
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->table('event_payouts', function (Blueprint $table): void {
            $table->dropColumn('provider_transfer_code');
        });
    }
};
