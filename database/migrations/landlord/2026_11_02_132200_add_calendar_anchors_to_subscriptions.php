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
        Schema::connection('landlord')->table('subscriptions', function (Blueprint $table): void {
            $table->unsignedSmallInteger('billing_anchor_day')->nullable();
            $table->unsignedSmallInteger('billing_anchor_month')->nullable();
        });
        DB::connection('landlord')->statement('ALTER TABLE subscriptions ADD CONSTRAINT subscriptions_calendar_anchor_valid CHECK ((billing_anchor_day IS NULL AND billing_anchor_month IS NULL) OR (billing_anchor_day IS NOT NULL AND billing_anchor_month IS NOT NULL AND billing_anchor_day BETWEEN 1 AND 31 AND billing_anchor_month BETWEEN 1 AND 12))');
    }

    public function down(): void
    {
        DB::connection('landlord')->statement('ALTER TABLE subscriptions DROP CONSTRAINT subscriptions_calendar_anchor_valid');
        Schema::connection('landlord')->table('subscriptions', function (Blueprint $table): void {
            $table->dropColumn(['billing_anchor_day', 'billing_anchor_month']);
        });
    }
};
