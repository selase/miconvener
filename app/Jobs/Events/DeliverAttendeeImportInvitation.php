<?php

declare(strict_types=1);

namespace App\Jobs\Events;

use App\Services\Events\AttendeeImportInvitations;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class DeliverAttendeeImportInvitation implements ShouldQueue
{
    use Queueable;

    public int $timeout = 60;

    public int $tries = 1;

    public function __construct(public readonly string $invitationId) {}

    public function handle(AttendeeImportInvitations $service): void
    {
        $service->deliver($this->invitationId);
    }
}
