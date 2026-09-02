<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use App\Services\Tenancy\TenantDatabaseManager;
use App\Services\Tenancy\TenantMigrator;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class TenantsMigrate extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tenants:migrate {--tenant= : The ID of the tenant to migrate}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Migrate tenant databases';

    /**
     * Execute the console command.
     */
    public function handle(TenantContext $context, TenantDatabaseManager $dbManager, TenantMigrator $migrator): int
    {
        $tenantId = (string) $this->option('tenant');

        $query = Tenant::where('status', 'active');
        if ($tenantId) {
            $query->where('id', $tenantId);
        }

        /** @var \Illuminate\Database\Eloquent\Collection<int, Tenant> $tenants */
        $tenants = $query->get();

        foreach ($tenants as $tenant) {
            $this->info("Migrating tenant: {$tenant->name} ({$tenant->id})");

            $context->setTenant($tenant);

            if ($tenant->encryption_at_rest && $tenant->requiresDedicatedDb() && empty($tenant->kms_key_ref)) {
                $this->error("Skipping tenant {$tenant->name}: Encryption enabled but no KMS key ref.");
                $this->logRun((string) $tenant->id, 'skipped', null, 'Missing KMS Key Ref', now(), now());

                continue;
            }

            if ($tenant->requiresDedicatedDb()) {
                $dbManager->configure($tenant);
            } else {
                $dbManager->configureShared();
            }

            $start = now();
            $status = 'success';
            $exception = null;
            $output = '';

            try {
                $result = $migrator->migrate('tenant', 'database/migrations/tenant', true);
                $exitCode = $result['exitCode'];
                $output = $result['output'];

                $this->line($output);

                if ($exitCode !== 0) {
                    $status = 'failed';
                    $exception = "Exit code: $exitCode";
                }

            } catch (Exception $e) {
                $status = 'failed';
                $exception = $e->getMessage();
                $this->error("Failed to migrate tenant: {$tenant->name}");
                $this->error($e->getMessage());
            }

            $finish = now();
            $this->logRun((string) $tenant->id, $status, $output, $exception, $start, $finish);
        }

        return Command::SUCCESS;
    }

    private function logRun(string $tenantId, string $status, ?string $output, ?string $exception, \Carbon\CarbonInterface $start, \Carbon\CarbonInterface $finish): void
    {
        DB::connection('landlord')->table('tenant_migration_runs')->insert([
            'tenant_id' => $tenantId,
            'migration_path' => 'database/migrations/tenant',
            'batch' => 0,
            'status' => $status,
            'output' => $output,
            'exception' => $exception,
            'started_at' => $start,
            'finished_at' => $finish,
        ]);
    }
}
