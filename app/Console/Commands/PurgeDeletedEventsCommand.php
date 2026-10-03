<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Event;
use Illuminate\Console\Command;

final class PurgeDeletedEventsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'events:purge-deleted {--dry-run : Simulate the purge without deleting records}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Permanently purge soft-deleted events whose 6-hour recovery window has elapsed';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $isDryRun = (bool) $this->option('dry-run');
        $this->info('Scanning for soft-deleted events eligible for permanent purge...');

        $baseQuery = Event::withoutGlobalScopes()
            ->onlyTrashed()
            ->whereNotNull('purge_at')
            ->where('purge_at', '<=', now());

        $totalCount = $baseQuery->count();
        if ($totalCount === 0) {
            $this->info('No expired deleted events found.');

            return self::SUCCESS;
        }

        $count = 0;
        $skipped = 0;

        $baseQuery->chunkById(50, function ($events) use ($isDryRun, &$count, &$skipped): void {
            foreach ($events as $event) {
                $blockReason = null;
                if (! $event->canBeDeleted($blockReason)) {
                    $this->warn("Skipping event: \"{$event->name}\" (ID: {$event->id}): {$blockReason}");
                    $skipped++;

                    continue;
                }

                $this->line("Purging event: \"{$event->name}\" (ID: {$event->id}, purge window elapsed at: {$event->purge_at->toIso8601String()})");

                if (! $isDryRun) {
                    $event->forceDelete();
                }

                $count++;
            }
        });

        $prefix = $isDryRun ? '[DRY RUN] Would purge' : 'Successfully purged';
        $this->info("{$prefix} {$count} expired event(s). (Skipped {$skipped} due to active blockers).");

        return self::SUCCESS;
    }
}
