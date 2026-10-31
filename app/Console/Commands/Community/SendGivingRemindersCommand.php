<?php

declare(strict_types=1);

namespace App\Console\Commands\Community;

use App\Services\Giving\GivingReceipts;
use App\Services\Giving\GivingReminders;
use Illuminate\Console\Command;

final class SendGivingRemindersCommand extends Command
{
    protected $signature = 'giving:send-reminders';

    protected $description = 'Dispatch consented Giving reminders and retry pending service receipts.';

    public function handle(GivingReminders $reminders, GivingReceipts $receipts): int
    {
        $reminders->sendDue();
        $receipts->retryQueued();

        return self::SUCCESS;
    }
}
