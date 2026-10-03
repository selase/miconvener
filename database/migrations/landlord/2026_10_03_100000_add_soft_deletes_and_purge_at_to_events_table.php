<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('landlord')->table('events', function (Blueprint $table) {
            $table->softDeletes()->after('updated_at');
            $table->timestamp('purge_at')->nullable()->after('deleted_at');
            $table->index(['tenant_id', 'deleted_at', 'purge_at'], 'idx_events_tenant_deleted_purge');
            $table->index('purge_at', 'idx_events_purge_at');
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->table('events', function (Blueprint $table) {
            $table->dropIndex('idx_events_tenant_deleted_purge');
            $table->dropIndex('idx_events_purge_at');
            $table->dropSoftDeletes();
            $table->dropColumn('purge_at');
        });
    }
};
