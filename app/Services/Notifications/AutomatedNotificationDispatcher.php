<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Models\Event;
use App\Models\EventNotificationLog;
use App\Models\EventNotificationRule;
use App\Models\EventParticipantGroup;
use App\Models\EventRegistration;
use App\Models\Speaker;
use App\Models\User;

final class AutomatedNotificationDispatcher
{
    public function __construct(
        private readonly NotificationGatewayService $gateway
    ) {}

    /**
     * Dispatch an automated notification rule to all eligible recipients.
     *
     * @return array{total_recipients: int, sent_count: int, staged_count: int, suppressed_count: int, rate_limited_count: int}
     */
    public function dispatchRule(EventNotificationRule $rule): array
    {
        $event = $rule->event;
        $recipients = $this->resolveRecipients($rule, $event);

        $stats = [
            'total_recipients' => count($recipients),
            'sent_count' => 0,
            'staged_count' => 0,
            'suppressed_count' => 0,
            'rate_limited_count' => 0,
        ];

        foreach ($recipients as $recipient) {
            $interpolatedSubject = $this->interpolateTemplate($rule->subject, $recipient, $event);
            $interpolatedBody = $this->interpolateTemplate($rule->body_template, $recipient, $event);

            $payload = [
                'subject' => $interpolatedSubject,
                'body' => $interpolatedBody,
                'action_url' => $recipient['action_url'] ?? url("/e/{$event->slug}"),
                'action_label' => $recipient['action_label'] ?? 'View Event',
            ];

            $results = $this->gateway->dispatch(
                event: $event,
                rule: $rule,
                recipient: $recipient,
                channels: $rule->channels ?? ['email'],
                payload: $payload
            );

            foreach ($results as $channelResult) {
                match ($channelResult['status']) {
                    EventNotificationLog::STATUS_SENT => $stats['sent_count']++,
                    EventNotificationLog::STATUS_STAGED => $stats['staged_count']++,
                    EventNotificationLog::STATUS_SUPPRESSED_QUOTA => $stats['suppressed_count']++,
                    'rate_limited' => $stats['rate_limited_count']++,
                    default => null,
                };
            }
        }

        $rule->update(['last_dispatched_at' => now()]);

        return $stats;
    }

    /**
     * Resolve recipient list according to target role and audience criteria.
     *
     * @return array<int, array{name: string, email: ?string, phone: ?string, ticket_code: ?string, action_url: ?string, action_label: ?string}>
     */
    public function resolveRecipients(EventNotificationRule $rule, Event $event): array
    {
        $role = $rule->target_role;
        $audience = $rule->target_audience;

        // 1. SPEAKER ROLE
        if ($role === EventNotificationRule::ROLE_SPEAKER || $audience === 'speakers') {
            return $event->speakers()->get()->map(fn (Speaker $s): array => [
                'name' => $s->name,
                'email' => $s->email,
                'phone' => null,
                'ticket_code' => null,
                'action_url' => url("/e/{$event->slug}"),
                'action_label' => 'Speaker Programme',
            ])->all();
        }

        // 2. ORGANIZER ROLE
        if ($role === EventNotificationRule::ROLE_ORGANIZER) {
            return User::where('tenant_id', $event->tenant_id)->get()->map(fn (User $u): array => [
                'name' => mb_trim("{$u->first_name} {$u->last_name}"),
                'email' => $u->email,
                'phone' => null,
                'ticket_code' => null,
                'action_url' => url("/events/{$event->id}"),
                'action_label' => 'Open Host Console',
            ])->all();
        }

        // 3. ATTENDEE ROLE & AUDIENCE STRATIFICATION
        $query = $event->registrations();

        if ($audience === 'all') {
            $query->whereIn('status', [EventRegistration::STATUS_CONFIRMED, EventRegistration::STATUS_CHECKED_IN]);
        } elseif ($audience === 'confirmed') {
            $query->where('status', EventRegistration::STATUS_CONFIRMED);
        } elseif ($audience === 'checked_in') {
            $query->where('status', EventRegistration::STATUS_CHECKED_IN);
        } elseif (str_starts_with($audience, 'ticket_type:')) {
            $ticketTypeId = mb_substr($audience, mb_strlen('ticket_type:'));
            $query->where('ticket_type_id', $ticketTypeId);
        } elseif (str_starts_with($audience, 'group:')) {
            $groupId = mb_substr($audience, mb_strlen('group:'));
            $group = EventParticipantGroup::find($groupId);
            if ($group) {
                $regIds = $group->members()->pluck('registration_id');
                $query->whereIn('id', $regIds);
            }
        }

        return $query->get()->map(fn (EventRegistration $reg): array => [
            'name' => $reg->full_name,
            'email' => $reg->email,
            'phone' => $reg->phone,
            'ticket_code' => $reg->ticket_code,
            'action_url' => url("/e/{$event->slug}/registrations/{$reg->id}"),
            'action_label' => 'View Digital Pass',
        ])->all();
    }

    /**
     * Replace template placeholders with real recipient & event context.
     *
     * @param  array{name: string, email: ?string, phone: ?string, ticket_code: ?string, action_url: ?string}  $recipient
     */
    public function interpolateTemplate(string $template, array $recipient, Event $event): string
    {
        $dateFormatted = $event->starts_at?->format('F j, Y') ?? 'TBD';
        $timeFormatted = $event->starts_at?->format('g:i A') ?? 'TBD';
        $venueName = $event->address ?? 'Online Conference';

        $replacements = [
            '{name}' => $recipient['name'],
            '{event_name}' => $event->name,
            '{date}' => $dateFormatted,
            '{time}' => $timeFormatted,
            '{venue}' => $venueName,
            '{ticket_code}' => $recipient['ticket_code'] ?? 'N/A',
            '{ticket_url}' => $recipient['action_url'] ?? url("/e/{$event->slug}"),
            '{portal_url}' => url("/e/{$event->slug}"),
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $template);
    }
}
