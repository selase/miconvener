<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Mail\Events\AutomatedNotificationMail;
use App\Models\Event;
use App\Models\EventNotificationLog;
use App\Models\EventNotificationRule;
use App\Models\TenantNotificationSetting;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Throwable;

final class NotificationGatewayService
{
    /**
     * Execute one atomically claimed delivery.
     *
     * @return array{status: string, message: string, cost: int}
     */
    public function deliver(EventNotificationLog $delivery): array
    {
        if (in_array($delivery->status, [
            EventNotificationLog::STATUS_SENT,
            EventNotificationLog::STATUS_STAGED,
            EventNotificationLog::STATUS_SUPPRESSED_QUOTA,
            EventNotificationLog::STATUS_SKIPPED,
            EventNotificationLog::STATUS_UNSUBSCRIBED,
        ], true)) {
            return [
                'status' => $delivery->status,
                'message' => 'Delivery is already terminal.',
                'cost' => (int) $delivery->cost_billed,
            ];
        }

        $delivery->forceFill([
            'attempts' => $delivery->attempts + 1,
            'last_attempted_at' => now(),
        ])->save();

        if ($delivery->channel === EventNotificationLog::CHANNEL_EMAIL && empty($delivery->recipient_email)) {
            return $this->finish($delivery, EventNotificationLog::STATUS_SKIPPED, 'No email address available for recipient.');
        }

        if (in_array($delivery->channel, [EventNotificationLog::CHANNEL_SMS, EventNotificationLog::CHANNEL_WHATSAPP], true) && empty($delivery->recipient_phone)) {
            return $this->finish($delivery, EventNotificationLog::STATUS_SKIPPED, 'No phone number available for recipient.');
        }

        if (! in_array($delivery->channel, [
            EventNotificationLog::CHANNEL_EMAIL,
            EventNotificationLog::CHANNEL_SMS,
            EventNotificationLog::CHANNEL_WHATSAPP,
        ], true)) {
            return $this->finish($delivery, EventNotificationLog::STATUS_SKIPPED, 'Unsupported notification channel.');
        }

        $settings = TenantNotificationSetting::forTenant($delivery->tenant_id);

        if (! $settings->canSend($delivery->channel)) {
            return $this->finish(
                $delivery,
                EventNotificationLog::STATUS_SUPPRESSED_QUOTA,
                "Sending blocked: {$delivery->channel} quota reached or channel disabled in settings.",
                metadata: ['reason' => 'Quota exceeded or channel disabled in tenant settings.'],
            );
        }

        if (in_array($delivery->channel, [EventNotificationLog::CHANNEL_SMS, EventNotificationLog::CHANNEL_WHATSAPP], true)) {
            $cost = $settings->recordSend($delivery->channel);

            return $this->finish(
                $delivery,
                EventNotificationLog::STATUS_STAGED,
                "Staged for Omnichannel {$delivery->channel} gateway dispatch. Not billed until delivered.",
                metadata: [
                    'provider' => 'omnichannel',
                    'channel' => $delivery->channel,
                    'to' => $delivery->recipient_phone,
                    'staged_at' => now()->toIso8601String(),
                    'would_bill' => $cost,
                ],
            );
        }

        $cost = $settings->recordSend(EventNotificationLog::CHANNEL_EMAIL);

        try {
            $metadata = $delivery->metadata ?? [];
            $event = $delivery->event;

            if (! $event instanceof Event) {
                throw new RuntimeException('The event for this notification no longer exists.');
            }

            Mail::to($delivery->recipient_email)->sendNow(new AutomatedNotificationMail(
                event: $event,
                recipientName: $delivery->recipient_name ?: 'Conference Participant',
                emailSubject: (string) $delivery->subject,
                renderedBody: (string) $delivery->message,
                actionUrl: $metadata['action_url'] ?? null,
                actionLabel: $metadata['action_label'] ?? 'View Event',
            ));

            return $this->finish($delivery, EventNotificationLog::STATUS_SENT, 'Email dispatched successfully.', $cost, sent: true);
        } catch (Throwable $e) {
            Log::error("Automated notification email failed: {$e->getMessage()}", [
                'delivery_id' => $delivery->id,
                'event_id' => $delivery->event_id,
                'email' => $delivery->recipient_email,
            ]);
            $settings->refundSend(EventNotificationLog::CHANNEL_EMAIL);

            return $this->finish(
                $delivery,
                EventNotificationLog::STATUS_FAILED,
                $e->getMessage(),
                metadata: ['error' => $e->getMessage()],
            );
        }
    }

    /**
     * Dispatch notification to a recipient across requested channels with quota and anti-abuse checks.
     *
     * @param  array{name?: ?string, email?: ?string, phone?: ?string}  $recipient
     * @param  array<string>  $channels
     * @param  array{subject: string, body: string, action_url?: ?string, action_label?: ?string}  $payload
     * @return array<string, array{status: string, message: string, cost: int}>
     */
    public function dispatch(
        Event $event,
        ?EventNotificationRule $rule,
        array $recipient,
        array $channels,
        array $payload
    ): array {
        $settings = TenantNotificationSetting::forTenant($event->tenant_id);
        $results = [];

        $name = $recipient['name'] ?? 'Conference Participant';
        $email = $recipient['email'] ?? null;
        $phone = $recipient['phone'] ?? null;

        foreach ($channels as $channel) {
            // Validate required recipient contact info
            if ($channel === EventNotificationLog::CHANNEL_EMAIL && empty($email)) {
                $results[$channel] = [
                    'status' => 'skipped',
                    'message' => 'No email address available for recipient.',
                    'cost' => 0,
                ];

                continue;
            }

            if (in_array($channel, [EventNotificationLog::CHANNEL_SMS, EventNotificationLog::CHANNEL_WHATSAPP], true) && empty($phone)) {
                $results[$channel] = [
                    'status' => 'skipped',
                    'message' => 'No phone number available for SMS/WhatsApp recipient.',
                    'cost' => 0,
                ];

                continue;
            }

            // Anti-abuse cooldown check (skip if notification recently sent to this recipient for this event)
            if ($this->isRateLimited($event->id, $email, $phone, $channel, $settings->anti_abuse_cooldown_minutes)) {
                $results[$channel] = [
                    'status' => 'rate_limited',
                    'message' => "Suppressed by anti-abuse cooldown ({$settings->anti_abuse_cooldown_minutes}m).",
                    'cost' => 0,
                ];

                continue;
            }

            // Quota and billing permission check
            if (! $settings->canSend($channel)) {
                EventNotificationLog::create([
                    'tenant_id' => $event->tenant_id,
                    'event_id' => $event->id,
                    'rule_id' => $rule?->id,
                    'recipient_name' => $name,
                    'recipient_email' => $email,
                    'recipient_phone' => $phone,
                    'channel' => $channel,
                    'status' => EventNotificationLog::STATUS_SUPPRESSED_QUOTA,
                    'subject' => $payload['subject'],
                    'message' => $payload['body'],
                    'cost_billed' => 0,
                    'metadata' => [
                        'reason' => 'Quota exceeded or channel disabled in tenant settings.',
                    ],
                    'sent_at' => null,
                ]);

                $results[$channel] = [
                    'status' => EventNotificationLog::STATUS_SUPPRESSED_QUOTA,
                    'message' => "Sending blocked: {$channel} quota reached or channel disabled in settings.",
                    'cost' => 0,
                ];

                continue;
            }

            // Record send against tenant quota and get calculated cost
            $cost = $settings->recordSend($channel);

            // Channel delivery execution
            if ($channel === EventNotificationLog::CHANNEL_EMAIL) {
                try {
                    // AutomatedNotificationMail implements ShouldQueue, so
                    // ->send() would only enqueue it and report success
                    // immediately -- the catch below would never see a real
                    // delivery failure, and STATUS_SENT/sent_at would be a
                    // claim about work that hadn't happened yet. sendNow()
                    // forces synchronous delivery so this block's status,
                    // timestamp, and refund are all true statements.
                    Mail::to($email)->sendNow(new AutomatedNotificationMail(
                        event: $event,
                        recipientName: $name,
                        emailSubject: $payload['subject'],
                        renderedBody: $payload['body'],
                        actionUrl: $payload['action_url'] ?? null,
                        actionLabel: $payload['action_label'] ?? 'View Event'
                    ));

                    EventNotificationLog::create([
                        'tenant_id' => $event->tenant_id,
                        'event_id' => $event->id,
                        'rule_id' => $rule?->id,
                        'recipient_name' => $name,
                        'recipient_email' => $email,
                        'recipient_phone' => $phone,
                        'channel' => EventNotificationLog::CHANNEL_EMAIL,
                        'status' => EventNotificationLog::STATUS_SENT,
                        'subject' => $payload['subject'],
                        'message' => $payload['body'],
                        'cost_billed' => $cost,
                        'sent_at' => now(),
                    ]);

                    $results[$channel] = [
                        'status' => EventNotificationLog::STATUS_SENT,
                        'message' => 'Email dispatched successfully.',
                        'cost' => $cost,
                    ];
                } catch (Throwable $e) {
                    Log::error("Automated notification email failed: {$e->getMessage()}", [
                        'event_id' => $event->id,
                        'email' => $email,
                    ]);

                    // The allowance was taken before the attempt; give it back.
                    $settings->refundSend(EventNotificationLog::CHANNEL_EMAIL);

                    EventNotificationLog::create([
                        'tenant_id' => $event->tenant_id,
                        'event_id' => $event->id,
                        'rule_id' => $rule?->id,
                        'recipient_name' => $name,
                        'recipient_email' => $email,
                        'recipient_phone' => $phone,
                        'channel' => EventNotificationLog::CHANNEL_EMAIL,
                        'status' => EventNotificationLog::STATUS_FAILED,
                        'subject' => $payload['subject'],
                        'message' => $payload['body'],
                        'cost_billed' => 0,
                        'metadata' => ['error' => $e->getMessage()],
                    ]);

                    $results[$channel] = [
                        'status' => EventNotificationLog::STATUS_FAILED,
                        'message' => $e->getMessage(),
                        'cost' => 0,
                    ];
                }
            } elseif (in_array($channel, [EventNotificationLog::CHANNEL_SMS, EventNotificationLog::CHANNEL_WHATSAPP], true)) {
                // Staged for Omnichannel Gateway integration. Nothing has been
                // delivered, so nothing is billed and nothing claims to have
                // been sent -- the rate is kept so the figure survives for when
                // a real gateway is wired in.
                $omnichannelPayload = [
                    'provider' => 'omnichannel',
                    'channel' => $channel,
                    'to' => $phone,
                    'recipient_name' => $name,
                    'message' => $payload['body'],
                    'tenant_id' => $event->tenant_id,
                    'event_id' => $event->id,
                    'staged_at' => now()->toIso8601String(),
                    'would_bill' => $cost,
                ];

                EventNotificationLog::create([
                    'tenant_id' => $event->tenant_id,
                    'event_id' => $event->id,
                    'rule_id' => $rule?->id,
                    'recipient_name' => $name,
                    'recipient_email' => $email,
                    'recipient_phone' => $phone,
                    'channel' => $channel,
                    'status' => EventNotificationLog::STATUS_STAGED,
                    'subject' => $payload['subject'],
                    'message' => $payload['body'],
                    'cost_billed' => 0,
                    'metadata' => $omnichannelPayload,
                    'sent_at' => null,
                ]);

                $results[$channel] = [
                    'status' => EventNotificationLog::STATUS_STAGED,
                    'message' => "Staged for Omnichannel {$channel} gateway dispatch. Not billed until delivered.",
                    'cost' => 0,
                ];
            }
        }

        return $results;
    }

    private function isRateLimited(string $eventId, ?string $email, ?string $phone, string $channel, int $cooldownMinutes): bool
    {
        if ($cooldownMinutes <= 0 || (! $email && ! $phone)) {
            return false;
        }

        $since = now()->subMinutes($cooldownMinutes);

        return EventNotificationLog::where('event_id', $eventId)
            ->where('channel', $channel)
            ->whereIn('status', [EventNotificationLog::STATUS_SENT, EventNotificationLog::STATUS_STAGED])
            ->where(function ($query) use ($email, $phone): void {
                if ($email) {
                    $query->where('recipient_email', $email);
                }
                if ($phone) {
                    $query->orWhere('recipient_phone', $phone);
                }
            })
            // created_at, not sent_at: a staged row has no sent_at, and the
            // cooldown means "when did we last try", not "when did we last
            // deliver".
            ->where('created_at', '>=', $since)
            ->exists();
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array{status: string, message: string, cost: int}
     */
    private function finish(
        EventNotificationLog $delivery,
        string $status,
        string $message,
        int $cost = 0,
        array $metadata = [],
        bool $sent = false,
    ): array {
        $delivery->forceFill([
            'status' => $status,
            'cost_billed' => $cost,
            'metadata' => array_merge($delivery->metadata ?? [], $metadata),
            'sent_at' => $sent ? now() : null,
        ])->save();

        return ['status' => $status, 'message' => $message, 'cost' => $cost];
    }
}
