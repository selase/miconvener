<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Mail\Events\AutomatedNotificationMail;
use App\Mail\Events\EventBlastMail;
use App\Models\SentEmail;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mime\Email;
use Throwable;

/**
 * Files every event email as it leaves, so Message delivery can answer "did
 * they get it?" for tickets, payment invites and the rest, which otherwise
 * left no trace.
 *
 * Only mail tagged with a tenant (BrandedForTenant) is filed. Announcements
 * and automated notifications are skipped because they are already recorded
 * (blast recipients and the notification log). A failure here must never stop
 * mail, so it is logged and swallowed.
 */
final class RecordSentEventEmail
{
    /**
     * @var list<class-string>
     */
    private const array ALREADY_RECORDED = [
        EventBlastMail::class,
        AutomatedNotificationMail::class,
    ];

    public function handle(MessageSent $event): void
    {
        $mailable = (string) ($event->data['__laravel_mailable'] ?? '');

        if ($mailable === '' || in_array($mailable, self::ALREADY_RECORDED, true)) {
            return;
        }

        $message = $event->sent->getOriginalMessage();

        if (! $message instanceof Email) {
            return;
        }

        $headers = $message->getHeaders();
        $tags = (string) $headers->get('X-SES-MESSAGE-TAGS')?->getBodyAsString();

        if (! preg_match('/tenant=([0-9a-f-]{36})/i', $tags, $match)) {
            return;
        }

        try {
            foreach ($message->getTo() as $recipient) {
                SentEmail::query()->create([
                    'tenant_id' => $match[1],
                    'event_id' => $headers->get('X-MiConvener-Event')?->getBodyAsString() ?: null,
                    'recipient_email' => mb_strtolower($recipient->getAddress()),
                    'subject' => mb_substr((string) $message->getSubject(), 0, 255),
                    'mailable' => class_basename($mailable),
                    'sent_at' => now(),
                ]);
            }
        } catch (Throwable $e) {
            Log::warning('Could not record a sent event email.', ['mailable' => $mailable, 'error' => $e->getMessage()]);
        }
    }
}
