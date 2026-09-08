<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use Illuminate\Console\Command;

final class SyncTenantFeaturesCommand extends Command
{
    protected $signature = 'tenants:sync-features {--tenant= : Sync a single tenant by id or slug} {--dry-run : Report what would be synced without writing}';

    protected $description = 'Re-apply each tenant\'s package features. Run after adding a feature key so gates see a real row instead of an absent one.';

    public function handle(): int
    {
        $query = Tenant::query()->whereNotNull('package_id');

        if ($tenantRef = $this->option('tenant')) {
            $query->where(function ($q) use ($tenantRef): void {
                $q->where('id', $tenantRef)->orWhere('slug', $tenantRef);
            });
        }

        if ($this->option('dry-run')) {
            $this->info("[Dry run] {$query->count()} tenant(s) would have their package features re-synced.");

            return self::SUCCESS;
        }

        $synced = 0;
        $query->chunkById(100, function ($tenants) use (&$synced): void {
            foreach ($tenants as $tenant) {
                $tenant->syncFeaturesFromPackage();
                $synced++;
            }
        });

        $skipped = Tenant::query()->whereNull('package_id')->count();

        $this->info("Synced features for {$synced} tenant(s).");

        if ($skipped > 0 && ! $this->option('tenant')) {
            $this->warn("{$skipped} tenant(s) have no package and were skipped. Run billing:backfill-free-package to place them on Free.");
        }

        return self::SUCCESS;
    }
}
