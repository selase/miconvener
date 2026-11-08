<?php

declare(strict_types=1);

namespace App\Jobs\Events;

use App\Services\Events\AttendeeImport;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class ProcessAttendeeImport implements ShouldQueue
{
    use Queueable;

    public int $timeout = 60;

    public int $tries = 3;

    public function __construct(public readonly string $importId) {}

    public function handle(AttendeeImport $service): void
    {
        $service->process($this->importId);
    }
}
