<?php

declare(strict_types=1);

namespace App\Services\Sms;

use App\Models\Event;
use App\Models\EventNotificationLog;
use App\Models\EventRegistration;
use App\Models\TenantNotificationSetting;
use App\Services\Notifications\NotificationDeliveryClaimService;
use App\Support\PhoneNumber;

/**
 * Texts an attendee their ticket when it is confirmed, alongside the ticket
 * email, if the organizer has switched SMS on. It goes through the same
 * claimed, queued delivery as every other notification, so it is sent once
 * per registration however many paths confirm it.
 */
final class TicketSms
{
    public const string NOTIFICATION_TYPE = 'ticket_confirmation';

    public function __construct(private readonly NotificationDeliveryClaimService $claims) {}

    public function send(EventRegistration $registration): ?EventNotificationLog
    {
        $event = $registration->event;

        if (! $event instanceof Event
            || ! $registration->isConfirmed()
            || blank($registration->ticket_code)
            || PhoneNumber::toInternationalDigits($registration->phone) === null
            || ! TenantNotificationSetting::forTenant($registration->tenant_id)->sms_enabled) {
            return null;
        }

        $delivery = $this->claims->claim(
            $event,
            null,
            self::NOTIFICATION_TYPE,
            'ticket-sms:'.$registration->id,
            ['name' => $registration->full_name, 'email' => $registration->email, 'phone' => $registration->phone],
            EventNotificationLog::CHANNEL_SMS,
            ['subject' => 'Your ticket for '.$event->name, 'body' => $this->text($registration, $event)],
            $registration,
        );

        if ($delivery instanceof EventNotificationLog) {
            $this->claims->dispatch($delivery);
        }

        return $delivery;
    }

    private function text(EventRegistration $registration, Event $event): string
    {
        $when = $event->starts_at->timezone($event->timezone ?: config('app.timezone'))->format('D j M, g:ia');
        $url = route('public.events.confirmation', [
            'subdomain' => $registration->tenant->slug,
            'event' => $event->slug,
            'registration' => $registration->id,
        ]);

        return trim(sprintf(
            "You're confirmed for %s on %s. Ticket %s. Show your QR code at the door: %s",
            $event->name,
            $when,
            $registration->ticket_code,
            $url,
        ));
    }
}
