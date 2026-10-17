<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Giving\GivingReceipts;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class DeliverGivingReceipt implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public int $backoff = 60;

    public function __construct(public string $noticeId) {}

    public function handle(GivingReceipts $receipts): void
    {
        $receipts->deliver($this->noticeId);
    }
}
