<?php

declare(strict_types=1);

namespace App\Jobs\Events;

use App\Jobs\Notifications\SendSmsBatchJob;
use App\Models\EventBlast;
use App\Models\EventNotificationLog;
use App\Services\Community\EventAnnouncementDelivery;
use App\Services\Community\MessagingPreferences;
use App\Services\Notifications\NotificationDeliveryClaimService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class SendEventBlastJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public EventBlast $blast) {}

    public function handle(): void
    {
        $this->blast->refresh();
        if ($this->blast->status === EventBlast::STATUS_CANCELLED || $this->blast->scheduled_at?->isFuture()) {
            return;
        }
        $event = $this->blast->event;
        $tenant = $this->blast->tenant;
        $claims = app(NotificationDeliveryClaimService::class);
        $preferences = app(MessagingPreferences::class);
        $delivery = app(EventAnnouncementDelivery::class);
        EventBlast::audienceQuery($event, $this->blast->audience)->chunkById(100, function ($registrations) use ($tenant, $claims, $preferences, $delivery, $event): void {
            $smsIds = [];
            foreach ($registrations as $registration) {
                if ($this->blast->fresh()->status === EventBlast::STATUS_CANCELLED) {
                    return;
                }
                $delivery->queueEmail($this->blast, $registration);
                $phone = $preferences->normalise('sms', (string) $registration->phone);
                if (! $this->blast->send_sms || $phone === null || ! $preferences->allowsMarketing($tenant, 'sms', $phone)) {
                    continue;
                }
                if (EventNotificationLog::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('dedupe_key', 'blast-sms:'.$this->blast->id.':'.$registration->id)->exists()) {
                    continue;
                }
                $claim = $claims->claim($event, null, 'announcement', 'blast-sms:'.$this->blast->id.':'.$preferences->hash('sms', $phone),
                    ['name' => $registration->full_name, 'email' => $registration->email, 'phone' => $phone], EventNotificationLog::CHANNEL_SMS,
                    ['subject' => $this->blast->subject, 'body' => $this->blast->subject.': '.$this->blast->body], $this->blast);
                if ($claim !== null) {
                    $claim->update(['metadata' => array_merge($claim->metadata ?? [], ['registration_id' => $registration->id])]);
                    $smsIds[] = $claim->id;
                }
            }
            foreach (array_chunk($smsIds, 50) as $ids) {
                SendSmsBatchJob::dispatch($this->blast->tenant_id, $ids);
            }
        });
        EventBlast::withoutGlobalScopes()->whereKey($this->blast->id)->where('status', '!=', EventBlast::STATUS_CANCELLED)
            ->update(['status' => EventBlast::STATUS_SENT, 'sent_at' => now()]);
    }
}
