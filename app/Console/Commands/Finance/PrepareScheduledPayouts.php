<?php

declare(strict_types=1);

namespace App\Console\Commands\Finance;

use App\Jobs\PrepareScheduledPayoutPolicy;
use App\Models\OrganisationPayoutPolicy;
use Illuminate\Console\Command;

final class PrepareScheduledPayouts extends Command
{
    protected $signature = 'payouts:prepare-schedules {--tenant= : Limit preparation to one organisation}';

    protected $description = 'Prepare approved payout proposals for human review; never submit transfers';

    public function handle(): int
    {
        OrganisationPayoutPolicy::query()->where('status', 'approved')
            ->when($this->option('tenant'), fn ($query, $tenant) => $query->where('tenant_id', $tenant))
            ->orderBy('id')->chunkById(50, function ($policies): void {
                foreach ($policies as $policy) {
                    PrepareScheduledPayoutPolicy::dispatch($policy->id);
                    $this->line($policy->id.': preparation queued; no transfer sent.');
                }
            });

        return self::SUCCESS;
    }
}
