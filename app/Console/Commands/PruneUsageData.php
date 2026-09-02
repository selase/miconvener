<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class PruneUsageData extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'usage:prune';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Prune old usage data according to retention policies';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $this->info('Pruning old usage data...');

        // 1. Retention for raw events: 7 days
        $eventsDeleted = DB::connection('landlord')->table('usage_events')
            ->where('occurred_at', '<', now()->subDays(7))
            ->delete();
        $this->line(" - Deleted {$eventsDeleted} raw usage events (Older than 7 days)");

        // 2. Retention for minute rollups: 14 days
        $minutesDeleted = DB::connection('landlord')->table('usage_rollups')
            ->where('period', 'minute')
            ->where('period_start', '<', now()->subDays(14))
            ->delete();
        $this->line(" - Deleted {$minutesDeleted} minute rollups (Older than 14 days)");

        // 3. Retention for hourly rollups: 3 months
        $hoursDeleted = DB::connection('landlord')->table('usage_rollups')
            ->where('period', 'hour')
            ->where('period_start', '<', now()->subMonths(3))
            ->delete();
        $this->line(" - Deleted {$hoursDeleted} hourly rollups (Older than 3 months)");

        $this->info('Pruning completed.');
    }
}
