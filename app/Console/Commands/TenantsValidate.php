<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use App\Services\Tenancy\TenantDatabaseManager;
use App\Services\Tenancy\TenantStorageManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class TenantsValidate extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tenants:validate {--tenant= : The ID of the tenant to validate}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Validate tenant configuration and infrastructure health';

    /**
     * Execute the console command.
     */
    public function handle(
        TenantContext $context,
        TenantDatabaseManager $dbManager,
        TenantStorageManager $storageManager
    ): int {
        $tenantId = (string) $this->option('tenant');

        $query = Tenant::query();
        if ($tenantId) {
            $query->where('id', $tenantId);
        }

        $tenants = $query->get();

        if ($tenants->isEmpty()) {
            $this->warn('No tenants found to validate.');

            return Command::SUCCESS;
        }

        $this->info("Validating {$tenants->count()} tenant(s)...");

        $headers = ['ID', 'Name', 'Slug', 'DB Status', 'Storage Status', 'Migrations'];
        $rows = [];

        foreach ($tenants as $tenant) {
            $this->comment("Checking tenant: {$tenant->name} ({$tenant->id})");

            $context->setTenant($tenant);

            // 1. Database Check
            $dbStatus = 'OK';
            try {
                if ($tenant->requiresDedicatedDb()) {
                    $dbManager->configure($tenant);
                } else {
                    $dbManager->configureShared();
                }

                DB::connection('tenant')->getPdo();
            } catch (Throwable $e) {
                $dbStatus = 'ERROR: '.$e->getMessage();
            }

            // 2. Storage Check
            $storageStatus = 'OK';
            try {
                $storageManager->configure($tenant);
                Storage::disk('tenant')->exists('.healthcheck');
            } catch (Throwable $e) {
                $storageStatus = 'ERROR: '.$e->getMessage();
            }

            // 3. Migration Check
            $migrationStatus = 'N/A';
            if ($dbStatus === 'OK') {
                try {
                    $tenantMigrationsPath = database_path('migrations/tenant');
                    $tenantMigrationFiles = glob($tenantMigrationsPath.'/*.php');
                    $totalMigrations = count($tenantMigrationFiles);

                    if ($tenant->requiresDedicatedDb()) {
                        $runMigrations = DB::connection('tenant')->table('migrations')->count();
                        $migrationStatus = "$runMigrations/$totalMigrations";
                    } else {
                        // In shared mode, the 'migrations' table is shared with landlord.
                        // We rely on the tenant_migration_runs table for tracking.
                        $lastRun = DB::connection('landlord')
                            ->table('tenant_migration_runs')
                            ->where('tenant_id', $tenant->id)
                            ->orderByDesc('finished_at')
                            ->first();

                        $migrationStatus = $lastRun ? ucfirst((string) $lastRun->status) : 'NEVER RUN';
                    }
                } catch (Throwable $e) {
                    $migrationStatus = 'ERROR: '.$e->getMessage();
                }
            }

            $rows[] = [
                $tenant->id,
                $tenant->name,
                $tenant->slug,
                $dbStatus,
                $storageStatus,
                $migrationStatus,
            ];
        }

        $this->table($headers, $rows);

        return Command::SUCCESS;
    }
}
