<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Contracts\SmsGateway;
use App\Jobs\Notifications\SendEventNotificationDeliveryJob;
use App\Mail\Events\AutomatedNotificationMail;
use App\Models\Event;
use App\Models\EventNotificationLog;
use App\Models\EventNotificationRule;
use App\Models\Tenant;
use App\Models\TenantNotificationSetting;
use App\Services\Sms\SmsAllowance;
use App\Services\Sms\SmsMessage;
use App\Services\Sms\SmsResult;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Throwable;

final class NotificationGatewayService
{
    /**
     * Three standard SMS segments. Longer texts are cut rather than sent as a
     * string of segments the attendee receives out of order.
     */
    public const int SMS_MAX_LENGTH = 459;

    public function __construct(
        private readonly SmsGateway $sms,
        private readonly SmsAllowance $smsAllowance,
    ) {}

    /**
     * Messages are written for email as well; an SMS gets them as plain text,
     * whitespace folded, and cut to fit.
     */
    public static function smsText(string $message): string
    {
        $text = mb_trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($message), ENT_QUOTES | ENT_HTML5)));

        return mb_strlen($text) > self::SMS_MAX_LENGTH
            ? mb_rtrim(mb_substr($text, 0, self::SMS_MAX_LENGTH - 1)).'…'
            : $text;
    }

    /**
     * Execute one atomically claimed delivery.
     *
     * @return array{status: string, message: string, cost: int, retry_after?: int}
     */
    public function deliver(EventNotificationLog $delivery): array
    {
        if ($delivery->channel === EventNotificationLog::CHANNEL_SMS) {
            return $this->deliverSmsBatch([$delivery])['results'][(string) $delivery->id];
        }

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

        if ($delivery->channel === EventNotificationLog::CHANNEL_WHATSAPP) {
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
     * @return array<string, array{status: string, message: string, cost: int, retry_after?: int}>
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

            if ($channel === EventNotificationLog::CHANNEL_SMS) {
                $delivery = EventNotificationLog::create([
                    'tenant_id' => $event->tenant_id,
                    'event_id' => $event->id,
                    'rule_id' => $rule?->id,
                    'recipient_name' => $name,
                    'recipient_email' => $email,
                    'recipient_phone' => $phone,
                    'channel' => EventNotificationLog::CHANNEL_SMS,
                    'status' => EventNotificationLog::STATUS_PENDING,
                    'subject' => $payload['subject'],
                    'message' => $payload['body'],
                    'cost_billed' => 0,
                ]);
                $results[$channel] = $this->deliver($delivery);

                if (isset($results[$channel]['retry_after'])) {
                    SendEventNotificationDeliveryJob::dispatch($delivery->id, $delivery->tenant_id)
                        ->delay(now()->addSeconds($results[$channel]['retry_after']));
                }

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
            } elseif ($channel === EventNotificationLog::CHANNEL_WHATSAPP) {
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

    /**
     * Hand claimed SMS deliveries to the provider together.
     *
     * Each is checked as deliver() checks one; those the tenant's credits do
     * not cover are suppressed. A credit is spent per message the provider
     * accepts. If the provider turns the request away for volume the messages
     * stay pending, nothing is spent, and retry_after says how long to wait.
     *
     * @param  iterable<EventNotificationLog>  $deliveries
     * @return array{results: array<string, array{status: string, message: string, cost: int, retry_after?: int}>, retry_after: ?int}
     */
    public function deliverSmsBatch(iterable $deliveries): array
    {
        $results = [];
        $sendable = [];

        foreach ($deliveries as $delivery) {
            $id = (string) $delivery->id;

            if (in_array($delivery->status, [
                EventNotificationLog::STATUS_SENT,
                EventNotificationLog::STATUS_SUPPRESSED_QUOTA,
                EventNotificationLog::STATUS_SKIPPED,
                EventNotificationLog::STATUS_UNSUBSCRIBED,
            ], true)) {
                $results[$id] = ['status' => $delivery->status, 'message' => 'Delivery is already terminal.', 'cost' => 0];

                continue;
            }

            $delivery->forceFill(['attempts' => $delivery->attempts + 1, 'last_attempted_at' => now()])->save();

            if (empty($delivery->recipient_phone)) {
                $results[$id] = $this->finish($delivery, EventNotificationLog::STATUS_SKIPPED, 'No phone number available for recipient.');

                continue;
            }

            if (! TenantNotificationSetting::forTenant($delivery->tenant_id)->canSend(EventNotificationLog::CHANNEL_SMS)) {
                $results[$id] = $this->finish(
                    $delivery,
                    EventNotificationLog::STATUS_SUPPRESSED_QUOTA,
                    'Sending blocked: SMS is switched off in settings.',
                    metadata: ['reason' => 'SMS disabled in tenant settings.'],
                );

                continue;
            }

            $sendable[] = $delivery;
        }

        $retryAfter = null;

        foreach (collect($sendable)->groupBy('tenant_id') as $tenantId => $tenantDeliveries) {
            $tenant = Tenant::query()->find($tenantId);

            if (! $tenant instanceof Tenant) {
                foreach ($tenantDeliveries as $delivery) {
                    $results[(string) $delivery->id] = $this->finish($delivery, EventNotificationLog::STATUS_SKIPPED, 'The organizer account no longer exists.');
                }

                continue;
            }

            $remaining = $this->smsAllowance->remaining($tenant);
            $covered = $remaining === null ? $tenantDeliveries : $tenantDeliveries->take($remaining);

            foreach ($tenantDeliveries->slice($covered->count()) as $delivery) {
                $results[(string) $delivery->id] = $this->finish(
                    $delivery,
                    EventNotificationLog::STATUS_SUPPRESSED_QUOTA,
                    'Sending blocked: no SMS credits left. Buy an SMS pack to keep sending.',
                    metadata: ['reason' => 'SMS credits used up.'],
                );
            }

            if ($covered->isEmpty()) {
                continue;
            }

            $sent = $this->sms->send($covered->map(fn (EventNotificationLog $delivery): SmsMessage => new SmsMessage(
                (string) $delivery->recipient_phone,
                self::smsText((string) $delivery->message),
                (string) $delivery->id,
            ))->values()->all());

            $accepted = 0;

            foreach ($covered as $delivery) {
                $id = (string) $delivery->id;
                $result = $sent[$id] ?? SmsResult::refused('The SMS provider returned no result.');

                if ($result->accepted) {
                    $accepted++;
                    $results[$id] = $this->finish(
                        $delivery,
                        EventNotificationLog::STATUS_SENT,
                        'SMS accepted by the provider for sending.',
                        metadata: ['provider_reference' => $result->providerReference],
                        sent: true,
                    );
                } elseif ($result->isThrottled()) {
                    $retryAfter = max($retryAfter ?? 0, (int) $result->retryAfter);
                    $results[$id] = $this->finish(
                        $delivery,
                        EventNotificationLog::STATUS_PENDING,
                        (string) $result->error,
                        metadata: ['last_error' => $result->error],
                    ) + ['retry_after' => (int) $result->retryAfter];
                } else {
                    Log::warning('Event SMS was not accepted', [
                        'delivery_id' => $delivery->id,
                        'event_id' => $delivery->event_id,
                        'error' => $result->error,
                    ]);
                    $results[$id] = $this->finish(
                        $delivery,
                        EventNotificationLog::STATUS_FAILED,
                        (string) $result->error,
                        metadata: ['error' => $result->error],
                    );
                }
            }

            $this->smsAllowance->consume($tenant, $accepted);
        }

        return ['results' => $results, 'retry_after' => $retryAfter];
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
