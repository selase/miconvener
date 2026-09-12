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

        $events = Event::published()->get();
        $totalRulesChecked = 0;
        $totalDispatched = 0;

        foreach ($events as $event) {
            $rules = $event->notificationRules()
                ->where('is_active', true)
                ->where('trigger_type', EventNotificationRule::TRIGGER_SCHEDULED_OFFSET)
                ->get();

            foreach ($rules as $rule) {
                $totalRulesChecked++;

                if ($rule->isDue()) {
                    $this->line("Dispatching due rule [{$rule->name}] for event [{$event->name}]...");
                    $stats = $dispatcher->dispatchRule($rule);

                    $this->info(" -> Dispatched to {$stats['total_recipients']} recipients ({$stats['sent_count']} emails sent, {$stats['staged_count']} SMS/WhatsApp staged, {$stats['suppressed_count']} quota suppressed).");
                    $totalDispatched++;
                }
            }
        }

        $this->info("Completed. Checked {$totalRulesChecked} active rules, dispatched {$totalDispatched} due campaigns.");

        return Command::SUCCESS;
    }
}
