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
        Schema::connection('landlord')->table('event_notification_logs', function (Blueprint $table): void {
            $table->string('notification_type')->nullable()->after('rule_id');
            $table->string('dedupe_key')->nullable()->after('notification_type');
            $table->string('source_type')->nullable()->after('dedupe_key');
            $table->string('source_id')->nullable()->after('source_type');
            $table->unsignedSmallInteger('attempts')->default(0)->after('cost_billed');
            $table->dateTime('last_attempted_at')->nullable()->after('sent_at');

            $table->index(['source_type', 'source_id'], 'event_notification_logs_source_index');
        });

        DB::connection('landlord')->statement(<<<'SQL'
            CREATE UNIQUE INDEX event_notification_logs_delivery_dedupe_unique
            ON event_notification_logs (tenant_id, channel, dedupe_key)
            WHERE dedupe_key IS NOT NULL
            SQL);
    }

    public function down(): void
    {
        DB::connection('landlord')->statement(
            'DROP INDEX IF EXISTS event_notification_logs_delivery_dedupe_unique'
        );

        Schema::connection('landlord')->table('event_notification_logs', function (Blueprint $table): void {
            $table->dropIndex('event_notification_logs_source_index');
            $table->dropColumn([
                'notification_type',
                'dedupe_key',
                'source_type',
                'source_id',
                'attempts',
                'last_attempted_at',
            ]);
        });
    }
};
