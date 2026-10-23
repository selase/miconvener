<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Marketplace\StoreReceipts;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class DeliverStoreReceipt implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public int $backoff = 300;

    public function __construct(public string $noticeId) {}

    public function handle(StoreReceipts $receipts): void
    {
        $receipts->deliver($this->noticeId);
    }
}
