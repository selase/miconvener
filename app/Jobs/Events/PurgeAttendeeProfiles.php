<?php

declare(strict_types=1);

namespace App\Jobs\Events;

use App\Services\Events\Profiles\ProfileRetention;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class PurgeAttendeeProfiles implements ShouldQueue
{
    use Queueable;

    public int $timeout = 60;

    public function handle(ProfileRetention $retention): void
    {
        $retention->sweep();
    }
}
