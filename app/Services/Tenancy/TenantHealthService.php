<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

use App\Models\Tenant;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Throwable;

final readonly class TenantHealthService
{
    public function __construct(
        private TenantDatabaseManager $dbManager,
        private TenantStorageManager $storageManager
    ) {}

    /**
     * Run all health checks for a tenant.
     */
    public function runAll(Tenant $tenant): array
    {
        return [
            'database' => $this->checkDatabase($tenant),
            'storage' => $this->checkStorage($tenant),
            'features' => $this->checkFeatures($tenant),
            'last_checked_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Check database connectivity and migration status.
     */
    public function checkDatabase(Tenant $tenant): array
    {
        try {
            // 1. Configure the connection
            $this->dbManager->configure($tenant);

            // 2. Test Connection
            $connection = DB::connection('tenant');
            $connection->getPdo(); // Triggers connection

            // 3. Check for users table as a proxy for successful migration
            $hasUsersTable = Schema::connection('tenant')->hasTable('users');

            return [
                'status' => 'ok',
                'message' => 'Connection successful.',
                'database_name' => $connection->getDatabaseName(),
                'has_schema' => $hasUsersTable,
            ];
        } catch (Throwable $e) {
            return [
                'status' => 'error',
                'message' => $e->getMessage(),
            ];
        } finally {
            $this->dbManager->purge();
        }
    }

    /**
     * Check if tenant storage is accessible.
     */
    public function checkStorage(Tenant $tenant): array
    {
        try {
            $this->storageManager->configure($tenant);
            $disk = Storage::disk('tenant');

            $testFile = 'health-check-'.$tenant->id.'.txt';
            $disk->put($testFile, 'health-check');
            $disk->delete($testFile);

            return [
                'status' => 'ok',
                'message' => 'Storage is writable.',
                'disk' => Config::get('filesystems.disks.tenant.driver'),
            ];
        } catch (Throwable $e) {
            return [
                'status' => 'error',
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Check if tenant features are synced with their package.
     */
    public function checkFeatures(Tenant $tenant): array
    {
        if (! $tenant->package_id) {
            return [
                'status' => 'warning',
                'message' => 'No subscription package assigned.',
            ];
        }

        $packageFeatureCount = DB::table('package_features')
            ->where('package_id', $tenant->package_id)
            ->count();

        $tenantFeatureCount = $tenant->features()->count();

        if ($packageFeatureCount !== $tenantFeatureCount) {
            return [
                'status' => 'warning',
                'message' => "Feature mismatch: Package has {$packageFeatureCount} features, Tenant has {$tenantFeatureCount}.",
            ];
        }

        return [
            'status' => 'ok',
            'message' => "All {$tenantFeatureCount} features are in sync.",
        ];
    }
}
