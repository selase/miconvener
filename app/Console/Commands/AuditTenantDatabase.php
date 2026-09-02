<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enum\UsageMetric;
use App\Models\Tenant;
use App\Services\Tenancy\DatabaseMeter;
use App\Services\Tenancy\UsageService;
use Illuminate\Console\Command;

final class AuditTenantDatabase extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tenants:audit-db';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Audit database usage for all tenants and record metrics';

    /**
     * Execute the console command.
     */
    public function handle(DatabaseMeter $meter, UsageService $usageService): void
    {
        $tenants = Tenant::all();

        $this->info("Starting database audit for {$tenants->count()} tenants...");

        foreach ($tenants as $tenant) {
            $this->comment("Auditing Tenant: {$tenant->name} ({$tenant->id})");

            $bytes = $meter->calculateUsage($tenant);

            $usageService->updateRollup(
                $tenant,
                'day',
                now(),
                UsageMetric::DB_BYTES,
                $bytes
            );

            $this->line(' - Footprint: '.number_format($bytes / 1024 / 1024, 2).' MB');
        }

        $this->info('Database audit completed.');
    }
}
