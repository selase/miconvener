<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

use App\Exceptions\TenantProvisioningException;
use App\Models\Tenant;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class TenantProvisioner
{
    /**
     * Provision a tenant's infrastructure and run migrations.
     */
    public function provision(Tenant $tenant): void
    {
        Log::info("Provisioning tenant: {$tenant->name} ({$tenant->id})");

        if ($tenant->isolation_mode === 'db_per_tenant') {
            $this->createDatabase($tenant);
        }

        $this->runMigrations($tenant);

        if ($tenant->package_id) {
            $tenant->syncFeaturesFromPackage();
        }
    }

    /**
     * Create a dedicated database for the tenant.
     */
    private function createDatabase(Tenant $tenant): void
    {
        // For now, we assume we're creating a database on the same host as the landlord.
        // We use a naming convention for the database name.
        $dbName = 'tenant_'.str_replace('-', '_', $tenant->id);

        Log::info("Creating database: {$dbName} for tenant {$tenant->id}");

        try {
            if ($tenant->db_driver === 'sqlite') {
                $dbPath = storage_path('tenants/'.$tenant->id.'.sqlite');
                if (! file_exists(dirname($dbPath))) {
                    mkdir(dirname($dbPath), 0755, true);
                }
                if (! file_exists($dbPath)) {
                    touch($dbPath);
                }
                $dbName = $dbPath;
            } elseif ($tenant->db_driver === 'pgsql') {
                // PostgreSQL doesn't allow CREATE DATABASE in a transaction, and Laravel often wraps statements.
                // We'll try to run it on the landlord connection.
                // We also need to make sure the database doesn't already exist.
                $exists = DB::connection('landlord')->select('SELECT 1 FROM pg_database WHERE datname = ?', [$dbName]);
                if (empty($exists)) {
                    DB::connection('landlord')->statement("CREATE DATABASE \"{$dbName}\"");
                }
            } else {
                DB::connection('landlord')->statement("CREATE DATABASE IF NOT EXISTS `{$dbName}`");
            }

            // Update the tenant's db_secret_ref if it was empty, or store it in meta.
            // For simplicity in this starter kit, if it's db_per_tenant without secret_ref,
            // we'll assume it uses landlord credentials but this specific DB name.
            // We'll store the database name in meta for the TenantDatabaseManager to find.
            $meta = $tenant->meta ?? [];
            $meta['database'] = $dbName;
            $tenant->meta = $meta;
            $tenant->save();

        } catch (Throwable $e) {
            Log::error("Failed to create database for tenant {$tenant->id}: ".$e->getMessage());
            throw TenantProvisioningException::databaseCreationFailed($tenant->id, $e);
        }
    }

    /**
     * Run migrations for the tenant.
     */
    private function runMigrations(Tenant $tenant): void
    {
        Log::info("Running migrations for tenant {$tenant->id}");

        try {
            Artisan::call('tenants:migrate', [
                '--tenant' => $tenant->id,
            ]);

            Log::info(Artisan::output());
        } catch (Throwable $e) {
            Log::error("Migration failed for tenant {$tenant->id}: ".$e->getMessage());
            throw TenantProvisioningException::migrationFailed($tenant->id, $e);
        }
    }
}
