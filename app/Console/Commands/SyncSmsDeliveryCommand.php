<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Sms\SmsDeliverySync;
use Illuminate\Console\Command;

/**
 * Asks the SMS provider what became of recently sent messages. See
 * SmsDeliverySync for the schedule and what is recorded.
 */
final class SyncSmsDeliveryCommand extends Command
{
    protected $signature = 'sms:sync-delivery';

    protected $description = 'Record whether recently sent SMS were delivered';

    public function handle(SmsDeliverySync $sync): int
    {
        $summary = $sync->run();

        $this->info(sprintf(
            '%d report(s) read: %d delivered, %d not delivered, %d still pending.',
            $summary['references'],
            $summary['delivered'],
            $summary['undelivered'],
            $summary['pending'],
        ));

        return self::SUCCESS;
    }
}
