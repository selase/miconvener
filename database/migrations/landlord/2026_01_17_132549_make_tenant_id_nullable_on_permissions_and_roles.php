<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $tableNames = config('permission.table_names');
        $columnNames = config('permission.column_names');
        $tenantId = $columnNames['team_foreign_key'] ?? 'tenant_id';

        if (empty($tableNames)) {
            return;
        }

        // Use Schema builder for better compatibility (requires doctrine/dbal)
        Schema::table('roles', function (Blueprint $table) use ($tenantId): void {
            $table->uuid($tenantId)->nullable()->change();
        });

        Schema::table('permissions', function (Blueprint $table) use ($tenantId): void {
            $table->uuid($tenantId)->nullable()->change();
        });
    }

    public function down(): void
    {
        // We cannot easily revert to NOT NULL without knowing if nulls exist
    }
};
