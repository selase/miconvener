<?php

declare(strict_types=1);

namespace App\Console\Commands\Billing;

use App\Models\Package;
use App\Models\Tenant;
use App\Services\Billing\SubscriptionProvisioningService;
use Illuminate\Console\Command;

final class BackfillFreePackageCommand extends Command
{
    protected $signature = 'billing:backfill-free-package {--dry-run : Report how many tenants would be affected without making any changes}';

    protected $description = 'One-off: assign every tenant without a package to the Free event-tier package.';

    public function handle(SubscriptionProvisioningService $provisioning): int
    {
        $freePackage = Package::where('is_free', true)->first();

        if (! $freePackage) {
            $this->error('No free package found. Run the EventPackageSeeder first.');

            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            $count = Tenant::whereNull('package_id')->count();
            $this->info("[Dry run] {$count} tenant(s) would be backfilled onto the Free package. No changes were made.");

            return self::SUCCESS;
        }

        $count = 0;
        Tenant::whereNull('package_id')->chunkById(100, function ($tenants) use ($provisioning, $freePackage, &$count): void {
            foreach ($tenants as $tenant) {
                $provisioning->upgrade($tenant, $freePackage);
                $count++;
            }
        });

        $this->info("Backfilled {$count} tenant(s) onto the Free package.");

        return self::SUCCESS;
    }
}
