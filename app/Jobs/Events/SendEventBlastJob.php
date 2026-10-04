<?php

declare(strict_types=1);

namespace App\Jobs\Events;

use App\Jobs\Notifications\SendSmsBatchJob;
use App\Mail\Events\EventBlastMail;
use App\Models\EventBlast;
use App\Models\EventBlastRecipient;
use App\Models\EventNotificationLog;
use App\Models\EventRegistration;
use App\Services\Notifications\NotificationDeliveryClaimService;
use App\Services\Tenancy\FeatureMeteringService;
use App\Support\PhoneNumber;
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
        $claims = app(NotificationDeliveryClaimService::class);

        EventBlast::audienceQuery($event, $this->blast->audience)
            ->chunk(100, function ($registrations) use ($tenant, $metering, $claims, $event): void {
                $smsDeliveryIds = [];

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

                    if ($this->blast->send_sms) {
                        $delivery = $this->claimSms($claims, $event, $registration);

                        if ($delivery instanceof EventNotificationLog) {
                            $smsDeliveryIds[] = (string) $delivery->id;
                        }
                    }
                }

                // Every recipient gets the same text, so a chunk is one
                // provider request rather than one per attendee.
                if ($smsDeliveryIds !== []) {
                    SendSmsBatchJob::dispatch($this->blast->tenant_id, $smsDeliveryIds);
                }
            });

        $this->blast->update(['status' => EventBlast::STATUS_SENT, 'sent_at' => now()]);
    }

    /**
     * The SMS copy is claimed as a notification delivery, which checks the
     * organizer's SMS switch and credits per message when it is sent. Keyed
     * by blast and registration, so a retried job never texts anyone twice.
     */
    private function claimSms(NotificationDeliveryClaimService $claims, \App\Models\Event $event, EventRegistration $registration): ?EventNotificationLog
    {
        if (PhoneNumber::toInternationalDigits($registration->phone) === null) {
            return null;
        }

        return $claims->claim(
            $event,
            null,
            'announcement',
            'blast-sms:'.$this->blast->id.':'.$registration->id,
            ['name' => $registration->full_name, 'email' => $registration->email, 'phone' => $registration->phone],
            EventNotificationLog::CHANNEL_SMS,
            ['subject' => $this->blast->subject, 'body' => $this->blast->subject.': '.$this->blast->body],
            $this->blast,
        );
    }
}
