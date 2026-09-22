<?php

declare(strict_types=1);

namespace App\Jobs\Events;

use App\Models\EventRegistration;
use App\Models\Tenant;
use App\Services\Events\AttendeeVerification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Does the address-dependent work of asking for a code, off the request
 * thread. The controller only validates and dispatches, so the time it takes
 * to reply never tells a caller whether an address (or a registration id)
 * means anything here -- looking that up, hashing a code and queuing mail all
 * happen after the response, in a worker.
 */
final class SendAttendeeAccessCode implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public Tenant $tenant,
        public ?string $email,
        public ?string $registrationId,
    ) {}

    public function handle(AttendeeVerification $verification): void
    {
        $email = $this->registrationId !== null
            ? EventRegistration::query()
                ->where('tenant_id', $this->tenant->id)
                ->where('id', $this->registrationId)
                ->value('email')
            : $this->email;

        if ($email === null) {
            return;
        }

        $verification->sendCode($this->tenant, (string) $email);
    }
}
