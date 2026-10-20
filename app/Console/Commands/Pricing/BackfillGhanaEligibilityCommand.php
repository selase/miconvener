<?php

declare(strict_types=1);

namespace App\Console\Commands\Pricing;

use App\Models\Tenant;
use App\Services\Pricing\GhanaEligibility;
use Illuminate\Console\Command;

/**
 * Marks existing customers Ghana eligible from what they have already done: a
 * verified Ghana payout account, or a payment by Ghana mobile money or a
 * Ghana-issued card. Nobody else is touched, and it is safe to run again.
 */
final class BackfillGhanaEligibilityCommand extends Command
{
    protected $signature = 'pricing:backfill-ghana {--dry-run : Report who would be marked without changing anything}';

    protected $description = 'Mark organisations with a verified Ghana payout account or a Ghana payment as eligible for Ghana pricing.';

    public function handle(GhanaEligibility $eligibility): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $bySource = [];
        $alreadyEligible = 0;
        $withoutEvidence = 0;

        foreach (Tenant::query()->orderBy('id')->cursor() as $tenant) {
            if ($tenant->isGhanaEligible()) {
                $alreadyEligible++;

                continue;
            }

            $evidence = $eligibility->grantableEvidence($tenant);

            if ($evidence === null) {
                $withoutEvidence++;

                continue;
            }

            $bySource[$evidence['source']] = ($bySource[$evidence['source']] ?? 0) + 1;

            if (! $dryRun) {
                $eligibility->grant($tenant, $evidence['source'], [...$evidence['detail'], 'backfilled' => true]);
            }
        }

        ksort($bySource);
        $total = array_sum($bySource);
        $breakdown = $bySource === [] ? 'none' : implode(', ', array_map(fn (string $source, int $count): string => "{$source} {$count}", array_keys($bySource), $bySource));

        $this->info(($dryRun ? 'Would mark' : 'Marked')." {$total} organisation(s) Ghana eligible ({$breakdown}).");
        $this->line("{$alreadyEligible} already eligible; {$withoutEvidence} without evidence, left as they are.");

        return self::SUCCESS;
    }
}
