<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\LlmTokenUsage;
use App\Models\LlmUsageSummary;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class AggregateLlmUsage extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'llm:aggregate-usage {--days=1 : Number of previous days to aggregate (including today)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Aggregate raw LLM token usage logs into summaries for faster reporting.';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $days = (int) $this->option('days');
        $startDate = Carbon::today()->subDays($days - 1);

        $this->info("Aggregating LLM usage from {$startDate->toDateString()}...");

        $usages = LlmTokenUsage::query()
            ->select([
                'tenant_id',
                'provider',
                'model',
                DB::raw('CAST(created_at AS DATE) as day'),
                DB::raw('SUM(prompt_tokens) as total_prompt_tokens'),
                DB::raw('SUM(completion_tokens) as total_completion_tokens'),
                DB::raw('SUM(total_tokens) as total_total_tokens'),
                DB::raw('SUM(cost_usd) as total_cost_usd'),
                DB::raw('COUNT(*) as request_count'),
            ])
            ->where('created_at', '>=', $startDate)
            ->groupBy(['tenant_id', 'provider', 'model', DB::raw('CAST(created_at AS DATE)')])
            ->get();

        if ($usages->isEmpty()) {
            $this->warn('No usage records found for the given range.');

            return;
        }

        $this->info("Processing {$usages->count()} aggregate groups...");

        foreach ($usages as $usage) {
            LlmUsageSummary::updateOrCreate(
                [
                    'tenant_id' => $usage->tenant_id,
                    'provider' => $usage->provider,
                    'model' => $usage->model,
                    'day' => $usage->day,
                ],
                [
                    'total_prompt_tokens' => $usage->total_prompt_tokens,
                    'total_completion_tokens' => $usage->total_completion_tokens,
                    'total_total_tokens' => $usage->total_total_tokens,
                    'total_cost_usd' => $usage->total_cost_usd,
                    'request_count' => $usage->request_count,
                ]
            );
        }

        $this->info('Aggregation completed successfully.');
    }
}
