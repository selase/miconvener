<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Event;
use App\Models\EventNotificationRule;
use App\Services\Notifications\AutomatedNotificationDispatcher;
use Illuminate\Console\Command;

final class DispatchAutomatedNotificationsCommand extends Command
{
    protected $signature = 'app:dispatch-automated-notifications';

    protected $description = 'Scan scheduled conference notification rules and dispatch automated reminders';

    public function handle(AutomatedNotificationDispatcher $dispatcher): int
    {
        $this->info('Scanning scheduled conference notification rules...');

        /*
         * The scheduler runs this every fifteen minutes across every tenant, so
         * the rules are fetched in one query with their event attached, rather
         * than walking the events and asking each one for its rules. isDue()
         * reads the event's start date, which is what made the walk cost a
         * further query per rule.
         */
        $rules = EventNotificationRule::query()
            ->with('event')
            ->where('is_active', true)
            ->where('trigger_type', EventNotificationRule::TRIGGER_SCHEDULED_OFFSET)
            ->whereIn('event_id', Event::published()->select('id'))
            ->get();

        $totalDispatched = 0;

        foreach ($rules as $rule) {
            if (! $rule->isDue()) {
                continue;
            }

            $this->line("Dispatching due rule [{$rule->name}] for event [{$rule->event->name}]...");
            $stats = $dispatcher->dispatchRule($rule);

            $this->info(" -> Dispatched to {$stats['total_recipients']} recipients ({$stats['sent_count']} emails sent, {$stats['staged_count']} SMS/WhatsApp staged, {$stats['suppressed_count']} quota suppressed).");
            $totalDispatched++;
        }

        $this->info("Completed. Checked {$rules->count()} active rules, dispatched {$totalDispatched} due campaigns.");

        return Command::SUCCESS;
    }
}
