<?php

declare(strict_types=1);

namespace App\Jobs\Events;

use App\Mail\Events\EventBlastMail;
use App\Models\EventBlast;
use App\Models\EventBlastRecipient;
use App\Services\Tenancy\FeatureMeteringService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

final class SendEventBlastJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public EventBlast $blast) {}

    public function handle(): void
    {
        if ($this->blast->status === EventBlast::STATUS_CANCELLED) {
            return;
        }

        $event = $this->blast->event;
        $tenant = $this->blast->tenant;
        $metering = app(FeatureMeteringService::class);

        EventBlast::audienceQuery($event, $this->blast->audience)
            ->chunk(100, function ($registrations) use ($tenant, $metering): void {
                foreach ($registrations as $registration) {
                    $recipient = EventBlastRecipient::firstOrCreate(
                        ['blast_id' => $this->blast->id, 'registration_id' => $registration->id],
                        ['tenant_id' => $this->blast->tenant_id],
                    );

                    Mail::to($registration->email)->send(new EventBlastMail($this->blast, $registration->full_name, $recipient->id));

                    // Charged per email actually sent: the audience can shrink
                    // between scheduling and delivery, and a cancelled blast
                    // should cost nothing.
                    if ($tenant) {
                        $metering->recordUsage($tenant, 'email_credits');
                    }
                }
            });

        $this->blast->update(['status' => EventBlast::STATUS_SENT, 'sent_at' => now()]);
    }
}
