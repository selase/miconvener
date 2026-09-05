<?php

declare(strict_types=1);

namespace App\Console\Commands\Tenants;

use App\Models\Tenant;
use Illuminate\Console\Command;

final class BackfillSettlementMode extends Command
{
    protected $signature = 'tenants:backfill-settlement-mode {--yes : Skip the confirmation prompt}';

    protected $description = 'One-time rollout step: sets every existing tenant to own_gateway settlement mode, since only genuinely new tenants should default to platform_default.';

    public function handle(): int
    {
        $count = Tenant::count();

        if (! $this->option('yes') && ! $this->confirm("This will set settlement_mode = own_gateway for all {$count} existing tenants. Continue?")) {
            $this->info('Aborted.');

            return self::SUCCESS;
        }

        $updated = Tenant::query()->update(['settlement_mode' => Tenant::SETTLEMENT_MODE_OWN_GATEWAY]);
        $this->info("Backfilled {$updated} tenant(s) to own_gateway settlement mode.");

        return self::SUCCESS;
    }
}
