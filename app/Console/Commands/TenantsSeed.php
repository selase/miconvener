<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use App\Services\Tenancy\TenantDatabaseManager;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

final class TenantsSeed extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tenants:seed 
                            {--tenant= : The ID of the tenant to seed}
                            {--class=DatabaseSeeder : The class name of the root seeder}
                            {--force : Force the operation to run when in production}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Seed tenant databases';

    /**
     * Execute the console command.
     */
    public function handle(TenantContext $context, TenantDatabaseManager $dbManager): int
    {
        $tenantId = (string) $this->option('tenant');
        $class = (string) $this->option('class');
        $force = (bool) $this->option('force');

        $query = Tenant::where('status', 'active');
        if ($tenantId) {
            $query->where('id', $tenantId);
        }

        /** @var \Illuminate\Database\Eloquent\Collection<int, Tenant> $tenants */
        $tenants = $query->get();

        foreach ($tenants as $tenant) {
            $this->info("Seeding tenant: {$tenant->name} ({$tenant->id})");

            $context->setTenant($tenant);

            if ($tenant->encryption_at_rest && $tenant->requiresDedicatedDb() && empty($tenant->kms_key_ref)) {
                $this->error("Skipping tenant {$tenant->name}: Encryption enabled but no KMS key ref.");

                continue;
            }

            if ($tenant->requiresDedicatedDb()) {
                $dbManager->configure($tenant);
            } else {
                $dbManager->configureShared();
            }

            try {
                // Determine connection to use. If dedicated DB, it's 'tenant'.
                // However, dbManager->configure() typically sets the 'tenant' connection.
                // Or updates 'database.connections.tenant.database'.

                // Let's rely on 'tenant' connection being configured by dbManager.
                Artisan::call('db:seed', [
                    '--class' => $class,
                    '--database' => 'tenant', // Important: force using the tenant connection
                    '--force' => $force,
                ]);

                $this->info(Artisan::output());

            } catch (Exception $e) {
                $this->error("Failed to seed tenant: {$tenant->name}");
                $this->error($e->getMessage());
            }
        }

        return Command::SUCCESS;
    }
}
