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
            $table->uuid('promo_code_id')->nullable()->after('ticket_type_id');
            $table->unsignedInteger('discount_amount')->default(0)->after('amount');

            $table->foreign('promo_code_id')->references('id')->on('event_promo_codes')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->table('event_registrations', function (Blueprint $table): void {
            $table->dropForeign(['promo_code_id']);
            $table->dropColumn(['promo_code_id', 'discount_amount']);
        });
    }
};
