<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Marketplace\StoreFulfilmentNotices;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class DeliverStoreReadyNotice implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public int $backoff = 300;

    public function __construct(public string $noticeId) {}

    public function handle(StoreFulfilmentNotices $receipts): void
    {
        $receipts->deliver($this->noticeId);
    }
}
