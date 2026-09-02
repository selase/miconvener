<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

final class ResetTenantUsageCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tenants:reset-usage {--tenant= : The ID or Slug of the tenant} {--feature= : The feature slug to reset}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Reset usage counts for metered features (e.g. monthly billing cycle)';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $service = app(\App\Services\Tenancy\FeatureMeteringService::class);
        $tenantQuery = \App\Models\Tenant::query();

        if ($this->option('tenant')) {
            $val = $this->option('tenant');
            $tenantQuery->where('id', $val)->orWhere('slug', $val);
        }

        // Processing in chunks to handle large datasets
        $tenantQuery->chunk(100, function ($tenants) use ($service): void {
            foreach ($tenants as $tenant) {
                $this->info("Resetting usage for tenant: {$tenant->name} ({$tenant->slug})");
                $service->resetUsage($tenant, $this->option('feature'));
            }
        });

        $this->info('Usage reset complete.');
    }
}
