<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Tenancy\UsageLimitService;
use Illuminate\Console\Command;

final class CheckUsageAlerts extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'usage:check-alerts';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Evaluate usage limits for all tenants and trigger alerts';

    /**
     * Execute the console command.
     */
    public function handle(UsageLimitService $limitService): void
    {
        $this->info('Checking usage alerts for all tenants...');

        $tenants = Tenant::where('status', 'active')->get();

        foreach ($tenants as $tenant) {
            $limitService->evaluateAlerts($tenant);
        }

        $this->info('Alert evaluation completed.');
    }
}
