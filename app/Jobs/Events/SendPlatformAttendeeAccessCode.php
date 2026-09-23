<?php

declare(strict_types=1);

namespace App\Jobs\Events;

use App\Services\Events\PlatformAttendeeVerification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Does the address-dependent work of generating and mailing a platform sign-in code off the request thread.
 */
final class SendPlatformAttendeeAccessCode implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public string $email,
    ) {}

    public function handle(PlatformAttendeeVerification $verification): void
    {
        $verification->dispatchCode($this->email);
    }
}
