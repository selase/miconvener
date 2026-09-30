<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Jobs\Notifications\SendEventNotificationDeliveryJob;
use App\Models\Event;
use App\Models\EventNotificationLog;
use App\Models\EventNotificationRule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class NotificationDeliveryClaimService
{
    /**
     * @param  array<string, mixed>  $recipient
     * @param  array{subject: string, body: string, action_url?: ?string, action_label?: ?string}  $payload
     */
    public function claim(
        Event $event,
        ?EventNotificationRule $rule,
        string $notificationType,
        string $dedupeKey,
        array $recipient,
        string $channel,
        array $payload,
        ?Model $source = null,
    ): ?EventNotificationLog {
        $recipient = $this->normalizeRecipient($recipient);
        $id = (string) Str::uuid7();
        $now = now();

        $inserted = DB::connection('landlord')->table('event_notification_logs')->insertOrIgnore([
            'id' => $id,
            'tenant_id' => $event->tenant_id,
            'event_id' => $event->id,
            'rule_id' => $rule?->id,
            'notification_type' => $notificationType,
            'dedupe_key' => $dedupeKey,
            'source_type' => $source?->getMorphClass(),
            'source_id' => $source?->getKey(),
            'recipient_name' => $recipient['name'],
            'recipient_email' => $recipient['email'],
            'recipient_phone' => $recipient['phone'],
            'channel' => $channel,
            'status' => EventNotificationLog::STATUS_PENDING,
            'subject' => $payload['subject'],
            'message' => $payload['body'],
            'cost_billed' => 0,
            'attempts' => 0,
            'metadata' => json_encode([
                'action_url' => $payload['action_url'] ?? null,
                'action_label' => $payload['action_label'] ?? null,
            ], JSON_THROW_ON_ERROR),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        if ($inserted === 0) {
            return null;
        }

        return EventNotificationLog::withoutGlobalScopes()->find($id);
    }

    public function dispatch(EventNotificationLog $delivery): void
    {
        DB::connection('landlord')->afterCommit(function () use ($delivery): void {
            SendEventNotificationDeliveryJob::dispatch($delivery->id, $delivery->tenant_id)->afterCommit();
        });
    }

    /**
     * @param  array<string, mixed>  $recipient
     * @return array{name: string, email: ?string, phone: ?string}
     */
    private function normalizeRecipient(array $recipient): array
    {
        $email = mb_strtolower(mb_trim((string) ($recipient['email'] ?? '')));
        $phone = preg_replace('/[^0-9+]/', '', mb_trim((string) ($recipient['phone'] ?? '')));

        return [
            'name' => mb_trim((string) ($recipient['name'] ?? '')) ?: 'Conference Participant',
            'email' => $email !== '' ? $email : null,
            'phone' => $phone !== '' ? $phone : null,
        ];
    }
}
