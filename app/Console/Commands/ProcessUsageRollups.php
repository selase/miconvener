<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class ProcessUsageRollups extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'usage:process-rollups {--period=hour : The period to aggregate into (hour|day)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Aggregate usage rollups from lower resolution (e.g. minute to hour)';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $targetPeriod = $this->option('period');
        $sourcePeriod = $targetPeriod === 'hour' ? 'minute' : 'hour';

        $this->info("Aggregating {$sourcePeriod} rollups into {$targetPeriod}...");

        // We aggregate the previous completed period
        $now = now();
        $startTime = $targetPeriod === 'hour'
            ? $now->copy()->subHour()->startOfHour()
            : $now->copy()->subDay()->startOfDay();

        $endTime = $targetPeriod === 'hour'
            ? $now->copy()->subHour()->endOfHour()
            : $now->copy()->subDay()->endOfDay();

        $this->comment("Processing period: {$startTime} to {$endTime}");

        $results = DB::connection('landlord')->table('usage_rollups')
            ->select([
                'tenant_id',
                'metric',
                'dimensions_hash',
                'dimensions',
                DB::raw('SUM(value) as total_value'),
            ])
            ->where('period', $sourcePeriod)
            ->whereBetween('period_start', [$startTime, $endTime])
            ->groupBy(['tenant_id', 'metric', 'dimensions_hash', 'dimensions'])
            ->get();

        foreach ($results as $row) {
            DB::connection('landlord')->table('usage_rollups')->updateOrInsert(
                [
                    'tenant_id' => $row->tenant_id,
                    'period' => $targetPeriod,
                    'period_start' => $startTime,
                    'metric' => $row->metric,
                    'dimensions_hash' => $row->dimensions_hash,
                ],
                [
                    'dimensions' => $row->dimensions,
                    'value' => $row->total_value,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }

        $this->info('Aggregation completed for '.$results->count().' records.');
    }
}
