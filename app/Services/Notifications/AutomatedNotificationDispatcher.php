<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Jobs\Notifications\SendSmsBatchJob;
use App\Models\Event;
use App\Models\EventMaterial;
use App\Models\EventNotificationLog;
use App\Models\EventNotificationRule;
use App\Models\EventParticipantGroup;
use App\Models\EventRegistration;
use App\Models\EventSpeaker;
use App\Models\Speaker;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class AutomatedNotificationDispatcher
{
    public function __construct(
        private readonly NotificationDeliveryClaimService $claims,
    ) {}

    /**
     * Dispatch an automated notification rule to all eligible recipients.
     *
     * @return array{total_recipients: int, claimed_count: int, sent_count: int, staged_count: int, suppressed_count: int, rate_limited_count: int}
     */
    public function dispatchScheduledRule(EventNotificationRule $rule): array
    {
        $target = $rule->calculateTargetTimestamp();
        $stats = $this->dispatchBulkRule($rule, 'scheduled:'.($target?->toIso8601String() ?? 'unknown'));

        $rule->update(['last_dispatched_at' => now()]);

        return $stats;
    }

    /**
     * Explicit bulk delivery used by the organizer's manual send action.
     *
     * @return array{total_recipients: int, claimed_count: int, sent_count: int, staged_count: int, suppressed_count: int, rate_limited_count: int}
     */
    public function dispatchBulkRule(EventNotificationRule $rule, string $occurrenceKey): array
    {
        $event = $this->ruleEvent($rule);

        return $this->dispatchRecipients($rule, $this->resolveRecipients($rule, $event), $occurrenceKey, $rule);
    }

    public function dispatchRegistrationRules(EventRegistration $registration): int
    {
        return $this->dispatchRegistrationOccurrence($registration, EventNotificationRule::TRIGGER_ON_REGISTRATION, 'registration:'.$registration->id);
    }

    public function dispatchCheckInRules(EventRegistration $registration, string $occurrenceKey): int
    {
        return $this->dispatchRegistrationOccurrence($registration, EventNotificationRule::TRIGGER_ON_CHECKIN, 'checkin:'.$registration->id.':'.$occurrenceKey);
    }

    public function dispatchMaterialRules(EventMaterial $material): int
    {
        $claimed = 0;
        $rules = EventNotificationRule::query()
            ->where('event_id', $material->event_id)
            ->where('trigger_type', EventNotificationRule::TRIGGER_ON_MATERIALS)
            ->where('is_active', true)
            ->get();

        foreach ($rules as $rule) {
            $event = $material->event;

            if (! $event instanceof Event) {
                continue;
            }

            $stats = $this->dispatchRecipients($rule, $this->resolveRecipients($rule, $event), 'material:'.$material->id, $material);
            $claimed += $stats['claimed_count'];
        }

        return $claimed;
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
        //
        // Queried through Speaker::whereIn() rather than $event->speakers()
        // (an untyped BelongsToMany) so the collection below is concretely
        // typed as Speaker, matching how the organiser branch below queries
        // User directly.
        if ($role === EventNotificationRule::ROLE_SPEAKER || $audience === 'speakers') {
            $speakerIds = EventSpeaker::where('event_id', $event->id)->pluck('speaker_id');

            return Speaker::whereIn('id', $speakerIds)->get()->map(fn (Speaker $s): array => [
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

    private function dispatchRegistrationOccurrence(EventRegistration $registration, string $trigger, string $occurrenceKey): int
    {
        $claimed = 0;
        $rules = EventNotificationRule::query()
            ->where('event_id', $registration->event_id)
            ->where('trigger_type', $trigger)
            ->where('is_active', true)
            ->get();

        foreach ($rules as $rule) {
            if (! $this->registrationMatches($rule, $registration)) {
                continue;
            }

            $stats = $this->dispatchRecipients($rule, [$this->registrationRecipient($registration)], $occurrenceKey, $registration);
            $claimed += $stats['claimed_count'];
        }

        return $claimed;
    }

    /**
     * @param  array<int, array{name: string, email: ?string, phone: ?string, ticket_code: ?string, action_url: ?string, action_label: ?string}>  $recipients
     * @return array{total_recipients: int, claimed_count: int, sent_count: int, staged_count: int, suppressed_count: int, rate_limited_count: int}
     */
    private function dispatchRecipients(EventNotificationRule $rule, array $recipients, string $occurrenceKey, \Illuminate\Database\Eloquent\Model $source): array
    {
        $event = $this->ruleEvent($rule);

        $stats = [
            'total_recipients' => count($recipients),
            'claimed_count' => 0,
            'sent_count' => 0,
            'staged_count' => 0,
            'suppressed_count' => 0,
            'rate_limited_count' => 0,
        ];

        /** @var list<EventNotificationLog> $claimed */
        $claimed = [];
        /** @var list<string> $smsDeliveryIds */
        $smsDeliveryIds = [];

        foreach ($recipients as $recipient) {
            $interpolatedSubject = $this->interpolateTemplate($rule->subject, $recipient, $event);
            $interpolatedBody = $this->interpolateTemplate($rule->body_template, $recipient, $event);

            $payload = [
                'subject' => $interpolatedSubject,
                'body' => $interpolatedBody,
                'action_url' => $recipient['action_url'] ?? url("/e/{$event->slug}"),
                'action_label' => $recipient['action_label'] ?? 'View Event',
            ];

            foreach ($rule->channels ?? ['email'] as $channel) {
                $identity = $channel === EventNotificationLog::CHANNEL_EMAIL
                    ? mb_strtolower((string) $recipient['email'])
                    : (string) $recipient['phone'];
                $dedupeKey = hash('sha256', implode('|', [$rule->id, $occurrenceKey, $identity, $channel]));
                $delivery = $this->claims->claim($event, $rule, $rule->trigger_type ?: 'manual', $dedupeKey, $recipient, $channel, $payload, $source);

                if (! $delivery instanceof EventNotificationLog) {
                    continue;
                }

                $stats['claimed_count']++;
                $claimed[] = $delivery;

                // SMS go out together (see below); other channels one by one.
                if ($channel === EventNotificationLog::CHANNEL_SMS) {
                    $smsDeliveryIds[] = (string) $delivery->id;
                } else {
                    $this->claims->dispatch($delivery);
                }
            }
        }

        // A personalised reminder to many people is a few provider requests,
        // not one per person: the SMS provider allows ten requests a minute.
        foreach (array_chunk($smsDeliveryIds, 100) as $batch) {
            $tenantId = (string) $event->tenant_id;
            DB::connection('landlord')->afterCommit(function () use ($tenantId, $batch): void {
                SendSmsBatchJob::dispatch($tenantId, $batch);
            });
        }

        foreach ($claimed as $delivery) {
            $delivery->refresh();

            match ($delivery->status) {
                EventNotificationLog::STATUS_SENT => $stats['sent_count']++,
                EventNotificationLog::STATUS_STAGED => $stats['staged_count']++,
                EventNotificationLog::STATUS_SUPPRESSED_QUOTA => $stats['suppressed_count']++,
                default => null,
            };
        }

        return $stats;
    }

    private function registrationMatches(EventNotificationRule $rule, EventRegistration $registration): bool
    {
        if ($rule->target_role !== EventNotificationRule::ROLE_ATTENDEE) {
            return false;
        }

        $audience = $rule->target_audience;

        if ($audience === 'all') {
            return in_array($registration->status, [EventRegistration::STATUS_CONFIRMED, EventRegistration::STATUS_CHECKED_IN], true);
        }

        if ($audience === 'confirmed' || $audience === 'checked_in') {
            return $registration->status === $audience;
        }

        if (str_starts_with($audience, 'ticket_type:')) {
            return $registration->ticket_type_id === mb_substr($audience, mb_strlen('ticket_type:'));
        }

        if (str_starts_with($audience, 'group:')) {
            $group = EventParticipantGroup::find(mb_substr($audience, mb_strlen('group:')));

            return $group?->members()->where('registration_id', $registration->id)->exists() ?? false;
        }

        return false;
    }

    private function ruleEvent(EventNotificationRule $rule): Event
    {
        $event = $rule->event;

        if (! $event instanceof Event) {
            throw new RuntimeException('The notification rule event no longer exists.');
        }

        return $event;
    }

    /** @return array{name: string, email: ?string, phone: ?string, ticket_code: ?string, action_url: ?string, action_label: ?string} */
    private function registrationRecipient(EventRegistration $registration): array
    {
        return [
            'name' => $registration->full_name,
            'email' => $registration->email,
            'phone' => $registration->phone,
            'ticket_code' => $registration->ticket_code,
            'action_url' => url("/e/{$registration->event->slug}/registrations/{$registration->id}"),
            'action_label' => 'View Digital Pass',
        ];
    }
}
