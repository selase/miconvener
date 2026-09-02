<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class DatabaseMeter
{
    /**
     * Calculate the database footprint for a tenant in bytes.
     */
    public function calculateUsage(Tenant $tenant): int
    {
        if ($tenant->requiresDedicatedDb()) {
            return $this->calculateDedicatedSize($tenant);
        }

        return $this->calculateSharedEstimate($tenant);
    }

    /**
     * Calculate actual size for a dedicated DB.
     */
    private function calculateDedicatedSize(Tenant $tenant): int
    {
        app(TenantDatabaseManager::class)->configure($tenant);
        $connection = DB::connection('tenant');
        $driver = $connection->getDriverName();

        return match ($driver) {
            'pgsql' => (int) $connection->selectOne('SELECT pg_database_size(current_database()) as size')->size,
            'mysql' => (int) $connection->selectOne('SELECT SUM(data_length + index_length) as size FROM information_schema.TABLES WHERE table_schema = DATABASE()')->size,
            'sqlite' => (int) filesize($connection->getDatabaseName()),
            default => 0,
        };
    }

    /**
     * Estimate footprint in a shared DB.
     */
    private function calculateSharedEstimate(Tenant $tenant): int
    {
        // For shared DB, we estimate based on row counts of tables with tenant_id
        // This is an approximation.
        $totalRows = 0;

        // List of major tenant-scoped tables
        $tables = [
            'users',
            'tenant_user',
            'model_has_roles',
            'model_has_permissions',
            // Add other core entities here
        ];

        foreach ($tables as $table) {
            if (Schema::connection('landlord')->hasColumn($table, 'tenant_id')) {
                $totalRows += DB::connection('landlord')->table($table)
                    ->where('tenant_id', $tenant->id)
                    ->count();
            }
        }

        // Assume average row size of 1KB (1024 bytes) as a starting point markup
        return $totalRows * 1024;
    }
}
