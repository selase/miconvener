<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Giving\GivingReminders;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class DeliverGivingReminder implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(public string $reminderId, public string $deliveryKey) {}

    public function handle(GivingReminders $reminders): void
    {
        $reminders->deliver($this->reminderId, $this->deliveryKey);
    }
}
