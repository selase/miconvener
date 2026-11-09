<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Finance\ScheduledPayoutNotices;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class DeliverScheduledPayoutNotice implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 45;

    public function __construct(public string $noticeId) {}

    public function handle(ScheduledPayoutNotices $notices): void
    {
        $notices->deliver($this->noticeId);
    }
}
