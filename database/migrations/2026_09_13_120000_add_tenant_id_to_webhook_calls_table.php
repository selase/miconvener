<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * WebhookCall uses BelongsToTenant, which writes tenant_id on every insert and
 * filters on it on every read, but the table was created without the column.
 * Every outbound webhook failed on insert on Postgres. The job's test built
 * its own copy of the table, with the column, so it never saw the real one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('webhook_calls', function (Blueprint $table): void {
            $table->foreignUuid('tenant_id')->nullable()->after('id')->constrained('tenants')->cascadeOnDelete();
        });

        // A call belongs to the tenant that owns its endpoint.
        DB::table('webhook_calls')
            ->whereNull('tenant_id')
            ->update([
                'tenant_id' => DB::raw('(select webhook_endpoints.tenant_id from webhook_endpoints where webhook_endpoints.id = webhook_calls.webhook_endpoint_id)'),
            ]);
    }

    public function down(): void
    {
        Schema::table('webhook_calls', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('tenant_id');
        });
    }
};
