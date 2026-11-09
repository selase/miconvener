<?php

declare(strict_types=1);

namespace App\Console\Commands\Finance;

use App\Models\OrganisationPayoutPolicy;
use App\Services\Finance\ScheduledPayoutPlanner;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

final class PrepareScheduledPayouts extends Command
{
    protected $signature = 'payouts:prepare-schedules {--tenant= : Limit preparation to one organisation}';

    protected $description = 'Prepare approved payout proposals for human review; never submit transfers';

    public function handle(ScheduledPayoutPlanner $planner): int
    {
        OrganisationPayoutPolicy::query()->where('status', 'approved')
            ->when($this->option('tenant'), fn ($query, $tenant) => $query->where('tenant_id', $tenant))
            ->orderBy('id')->chunkById(50, function ($policies) use ($planner): void {
                foreach ($policies as $policy) {
                    $count = count($planner->prepare($policy, CarbonImmutable::now()));
                    $this->line($policy->id.': '.$count.' proposal(s); no transfer sent.');
                }
            });

        return self::SUCCESS;
    }
}
