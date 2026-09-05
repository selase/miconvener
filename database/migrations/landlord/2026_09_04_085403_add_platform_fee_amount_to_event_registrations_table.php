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
            $table->bigInteger('platform_fee_amount')->default(0)->after('amount');
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->table('event_registrations', function (Blueprint $table): void {
            $table->dropColumn('platform_fee_amount');
        });
    }
};
