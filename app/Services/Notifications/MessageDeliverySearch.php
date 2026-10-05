<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Models\BillingEmail;
use App\Models\EventBlastRecipient;
use App\Models\EventNotificationLog;
use App\Models\EventRegistration;
use App\Models\Tenant;

/**
 * Answers support's commonest question, "did this person get our message?",
 * across every organisation: event notifications and texts (with the
 * provider's error when one failed), announcement emails, and billing emails.
 */
final class MessageDeliverySearch
{
    private const int LIMIT = 100;

    /**
     * @return list<array{at: ?string, sort: int, organisation: string, kind: string, channel: string, to: string, subject: string, status: string, error: ?string}>
     */
    public function find(string $term): array
    {
        $term = mb_trim($term);

        if (mb_strlen($term) < 4) {
            return [];
        }

        $isEmail = str_contains($term, '@');
        $digits = preg_replace('/\D/', '', $term) ?? '';
        // Ghana numbers are stored as +233…, typed as 024… or 24…: match the last nine digits.
        $phoneTail = mb_strlen($digits) >= 9 ? mb_substr($digits, -9) : null;

        if (! $isEmail && $phoneTail === null) {
            return [];
        }

        $rows = collect([
            ...$this->notifications($term, $isEmail, $phoneTail),
            ...($isEmail ? $this->announcements($term) : []),
            ...($isEmail ? $this->billingEmails($term) : []),
        ]);

        $names = Tenant::query()->whereIn('id', $rows->pluck('tenant_id')->filter()->unique())->pluck('name', 'id');

        return $rows->sortByDesc('sort')
            ->take(self::LIMIT)
            ->map(fn (array $row): array => [
                'at' => $row['at'],
                'sort' => $row['sort'],
                'organisation' => (string) ($names[$row['tenant_id']] ?? '—'),
                'kind' => $row['kind'],
                'channel' => $row['channel'],
                'to' => $row['to'],
                'subject' => $row['subject'],
                'status' => $row['status'],
                'error' => $row['error'],
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function notifications(string $term, bool $isEmail, ?string $phoneTail): array
    {
        return EventNotificationLog::withoutGlobalScopes()
            ->when($isEmail, fn ($query) => $query->where('recipient_email', 'ilike', $term))
            ->when(! $isEmail, fn ($query) => $query->where('recipient_phone', 'like', "%{$phoneTail}"))
            ->latest('id')
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (EventNotificationLog $log): array => [
                'tenant_id' => $log->tenant_id,
                'at' => ($log->sent_at ?? $log->created_at)?->format('j M Y H:i'),
                'sort' => (int) ($log->sent_at ?? $log->created_at)?->timestamp,
                'kind' => str_replace('_', ' ', (string) ($log->notification_type ?: 'notification')),
                'channel' => mb_strtoupper((string) $log->channel),
                'to' => (string) ($log->channel === EventNotificationLog::CHANNEL_SMS ? $log->recipient_phone : $log->recipient_email),
                'subject' => (string) ($log->subject ?: mb_substr((string) $log->message, 0, 80)),
                'status' => str_replace('_', ' ', (string) $log->status),
                'error' => isset($log->metadata['error']) ? (string) $log->metadata['error'] : null,
            ])
            ->values()
            ->all();
    }

    /**
     * Announcement emails are recorded per registration, so find this
     * person's registrations first.
     *
     * @return list<array<string, mixed>>
     */
    private function announcements(string $email): array
    {
        $registrations = EventRegistration::withoutGlobalScopes()->where('email', 'ilike', $email)->pluck('email', 'id');

        if ($registrations->isEmpty()) {
            return [];
        }

        return EventBlastRecipient::withoutGlobalScopes()
            ->with(['blast' => fn ($query) => $query->withoutGlobalScopes()])
            ->whereIn('registration_id', $registrations->keys())
            ->latest('id')
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (EventBlastRecipient $recipient): array => [
                'tenant_id' => $recipient->tenant_id,
                'at' => $recipient->created_at?->format('j M Y H:i'),
                'sort' => (int) $recipient->created_at?->timestamp,
                'kind' => 'announcement',
                'channel' => 'EMAIL',
                'to' => (string) $registrations[$recipient->registration_id],
                'subject' => (string) ($recipient->blast->subject ?? 'Announcement'),
                'status' => $recipient->opened_at ? 'sent, opened '.$recipient->opened_at->format('j M H:i') : 'sent',
                'error' => null,
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function billingEmails(string $email): array
    {
        return BillingEmail::query()
            ->whereJsonContains('recipients', mb_strtolower($email))
            ->latest('created_at')
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (BillingEmail $billing): array => [
                'tenant_id' => $billing->tenant_id,
                'at' => $billing->created_at?->format('j M Y H:i'),
                'sort' => (int) $billing->created_at?->timestamp,
                'kind' => 'billing: '.str_replace('_', ' ', (string) $billing->type),
                'channel' => 'EMAIL',
                'to' => mb_strtolower($email),
                'subject' => str_replace('_', ' ', ucfirst((string) $billing->type)),
                'status' => 'sent',
                'error' => null,
            ])
            ->values()
            ->all();
    }
}
