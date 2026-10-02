<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Event;
use App\Services\Events\RecurrenceService;
use Illuminate\Console\Command;

final class GenerateRecurringSessionsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'events:generate-recurring-sessions {--event= : Specific event UUID to generate occurrences for}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate rolling future session occurrences for active recurring events';

    /**
     * Execute the console command.
     */
    public function handle(RecurrenceService $recurrenceService): int
    {
        $this->info('Starting recurring occurrences generation...');

        $query = Event::where('is_recurring', true)->published();

        if ($eventId = $this->option('event')) {
            $query->where('id', $eventId);
        }

        $totalGenerated = 0;
        $totalEvents = 0;

        $query->chunk(50, function ($events) use ($recurrenceService, &$totalGenerated, &$totalEvents): void {
            foreach ($events as $event) {
                $occurrences = $recurrenceService->generateOccurrences($event);
                $count = $occurrences->count();
                $totalGenerated += $count;
                $totalEvents++;

                $this->line("Event: {$event->name} ({$event->id}) - verified/generated {$count} occurrences.");
            }
        });

        if ($totalEvents === 0) {
            $this->info('No published recurring events found.');

            return self::SUCCESS;
        }

        $this->info("Successfully processed {$totalEvents} recurring events ({$totalGenerated} occurrences active).");

        return self::SUCCESS;
    }
}
