<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enum\UsageMetric;
use App\Models\Tenant;
use App\Services\Tenancy\StorageMeter;
use App\Services\Tenancy\UsageService;
use Illuminate\Console\Command;

final class AuditTenantStorage extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tenants:audit-storage';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Audit storage usage for all tenants and record metrics';

    /**
     * Execute the console command.
     */
    public function handle(StorageMeter $meter, UsageService $usageService): void
    {
        $tenants = Tenant::all();

        $this->info("Starting storage audit for {$tenants->count()} tenants...");

        foreach ($tenants as $tenant) {
            $this->comment("Auditing Tenant: {$tenant->name} ({$tenant->id})");

            $bytes = $meter->calculateUsage($tenant);

            $usageService->updateRollup(
                $tenant,
                'day',
                now(),
                UsageMetric::STORAGE_BYTES,
                $bytes
            );

            $this->line(' - Usage: '.number_format($bytes / 1024 / 1024, 2).' MB');
        }

        $this->info('Storage audit completed.');
    }
}
