<?php

declare(strict_types=1);

namespace App\Console\Commands\Community;

use App\Models\Tenant;
use App\Services\Giving\GivingContributionBridge;
use Illuminate\Console\Command;

final class ReconcileGivingCommand extends Command
{
    protected $signature = 'giving:reconcile {--tenant=}';

    protected $description = 'Read-only Giving source, reference, ledger and payout reconciliation.';

    public function handle(GivingContributionBridge $bridge): int
    {
        $tenants = Tenant::query()->when($this->option('tenant'), fn ($q) => $q->where('slug', $this->option('tenant')))->get();
        if ($tenants->isEmpty()) {
            $this->error('Organisation not found.');

            return self::FAILURE;
        }
        $failed = false;
        foreach ($tenants as $tenant) {
            $result = $bridge->reconcile($tenant);
            $this->line(json_encode($result, JSON_THROW_ON_ERROR));
            $failed = $failed || $result['discrepancies'] !== [];
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
