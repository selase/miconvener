<?php

declare(strict_types=1);

namespace App\Jobs\Community;

use App\Services\Community\ContactNotices;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class DeliverContactNotice implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public string $noticeId) {}

    public function handle(ContactNotices $notices): void
    {
        $notices->deliver($this->noticeId);
    }
}
