<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Marketplace\VenueQuoteNotices;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Sends one quote notice. The notice row is claimed before sending, and a failed
 * send is retried by the scheduled `venue:deliver-quote-notices`, not by the queue.
 */
final class DeliverVenueQuoteNotice implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public string $noticeId) {}

    public function handle(VenueQuoteNotices $notices): void
    {
        $notices->deliver($this->noticeId);
    }
}
