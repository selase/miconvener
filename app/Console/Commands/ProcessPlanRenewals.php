<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Billing\RenewalScheduler;
use Illuminate\Console\Command;

final class ProcessPlanRenewals extends Command
{
    protected $signature = 'billing:process-renewals {--pretend : List what would happen without charging, emailing or changing anything}';

    protected $description = 'Charge, remind, or move to Free each tenant whose plan period is ending';

    public function handle(RenewalScheduler $scheduler): int
    {
        $actions = $scheduler->run(pretend: (bool) $this->option('pretend'));

        foreach ($actions as $action) {
            $this->line($action);
        }

        $this->info(($this->option('pretend') ? 'Would take ' : 'Took ').count($actions).' renewal action(s).');

        return self::SUCCESS;
    }
}
