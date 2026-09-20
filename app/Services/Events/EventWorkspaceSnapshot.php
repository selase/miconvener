<?php

declare(strict_types=1);

namespace App\Services\Events;

use App\Models\Event;
use App\Models\EventCertificate;
use App\Models\EventNotificationRule;
use App\Models\EventRegistration;
use App\Models\EventServiceRequest;
use App\Models\Tenant;
use App\Models\User;

/**
 * What the workspace should lead with, given where the event is in its life.
 *
 * The menu deliberately never reorders itself -- people learn where things are
 * -- so the event's timing shows up in two other places instead: badges beside
 * the sections that need someone, and an overview that leads with the work of
 * the moment. Before the doors open that is approvals and readiness; on the day
 * it is arrivals and help requests; afterwards it is certificates and payout.
 */
final class EventWorkspaceSnapshot
{
    public const string BEFORE = 'before';

    public const string LIVE = 'live';

    public const string AFTER = 'after';

    public function phase(Event $event): string
    {
        $now = now();

        return match (true) {
            $event->ends_at->lt($now) => self::AFTER,
            $event->starts_at->lte($now) => self::LIVE,
            default => self::BEFORE,
        };
    }

    /**
     * Badges for the sections this user can actually open. A count nobody can
     * act on is just noise.
     *
     * @return array<string, array{kind: string, label: string|int, title?: string}>
     */
    public function badges(User $user, Tenant $tenant, Event $event, string $phase): array
    {
        $badges = [];
        $sections = app(EventSections::class);
        $can = fn (string $slug): bool => $sections->allows($user, $tenant, $event, $slug);

        if ($can('guests')) {
            $waiting = $event->registrations()
                ->where('status', EventRegistration::STATUS_PENDING_APPROVAL)
                ->count();

            if ($waiting > 0) {
                $badges['guests'] = [
                    'kind' => 'attention',
                    'label' => $waiting,
                    'title' => 'Waiting for your approval',
                ];
            }
        }

        if ($phase === self::LIVE && $can('check-in')) {
            $badges['check-in'] = ['kind' => 'live', 'label' => 'Live'];
        }

        if ($can('help-requests')) {
            $open = $event->serviceRequests()
                ->whereIn('status', [
                    EventServiceRequest::STATUS_OPEN,
                    EventServiceRequest::STATUS_ACKNOWLEDGED,
                    EventServiceRequest::STATUS_IN_PROGRESS,
                ])
                ->count();

            if ($open > 0) {
                $badges['help-requests'] = [
                    'kind' => 'attention',
                    'label' => $open,
                    'title' => 'Open help requests',
                ];
            }
        }

        if ($phase === self::AFTER && $can('certificates') && $this->attendedCount($event) > 0) {
            $issued = EventCertificate::query()
                ->where('event_id', $event->id)
                ->whereNotNull('issued_at')
                ->exists();

            if (! $issued) {
                $badges['certificates'] = ['kind' => 'todo', 'label' => 'To issue'];
            }
        }

        return $badges;
    }

    /**
     * The overview's headline work for this moment, with real signals only:
     * anything shown here is something the organizer can act on now.
     *
     * @return array<string, mixed>
     */
    public function overview(User $user, Tenant $tenant, Event $event, string $phase, bool $hasActiveGateway): array
    {
        $sections = app(EventSections::class);
        $can = fn (string $slug): bool => $sections->allows($user, $tenant, $event, $slug);

        return match ($phase) {
            self::LIVE => [
                'phase' => $phase,
                'checked_in' => $event->registrations()->where('status', EventRegistration::STATUS_CHECKED_IN)->count(),
                'expected' => $this->expectedCount($event),
                'open_requests' => $can('help-requests') ? $this->openRequests($event) : [],
                'waiting_approval' => $can('guests') ? $this->waitingForApproval($event) : [],
                'can_check_in' => $can('check-in'),
            ],
            self::AFTER => [
                'phase' => $phase,
                'attended' => $this->attendedCount($event),
                'expected' => $this->expectedCount($event),
                'wrap_up' => $this->wrapUp($event, $can),
            ],
            default => [
                'phase' => $phase,
                'opens_in_days' => (int) ceil(now()->diffInDays($event->starts_at, false)),
                'waiting_approval' => $can('guests') ? $this->waitingForApproval($event) : [],
                'readiness' => $this->readiness($event, $hasActiveGateway, $can),
            ],
        };
    }

    /**
     * @return list<array{id: string, full_name: string, email: string, ticket_type_name: string|null, amount: int, currency: string, waiting_since: string}>
     */
    private function waitingForApproval(Event $event): array
    {
        return $event->registrations()
            ->where('status', EventRegistration::STATUS_PENDING_APPROVAL)
            ->with('ticketType:id,name')
            ->orderBy('created_at')
            ->limit(5)
            ->get()
            ->map(fn (EventRegistration $r): array => [
                'id' => $r->id,
                'full_name' => $r->full_name,
                'email' => $r->email,
                'ticket_type_name' => $r->ticketType?->name,
                'amount' => (int) $r->amount,
                'currency' => $r->currency ?? $event->currency,
                'waiting_since' => $r->created_at->diffForHumans(),
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array{id: string, type: string, location: string|null, waiting_since: string}>
     */
    private function openRequests(Event $event): array
    {
        return $event->serviceRequests()
            ->whereIn('status', [
                EventServiceRequest::STATUS_OPEN,
                EventServiceRequest::STATUS_ACKNOWLEDGED,
                EventServiceRequest::STATUS_IN_PROGRESS,
            ])
            ->orderBy('created_at')
            ->limit(3)
            ->get()
            ->map(fn (EventServiceRequest $r): array => [
                'id' => $r->id,
                'type' => ucfirst(str_replace('_', ' ', (string) $r->type)),
                'location' => $r->location,
                'waiting_since' => $r->created_at?->diffForHumans() ?? '',
            ])
            ->values()
            ->all();
    }

    /**
     * Checks worth making before the doors open, each one a real signal with
     * somewhere to go and fix it.
     *
     * @param  callable(string): bool  $can
     * @return list<array{key: string, label: string, done: bool, detail: string, section: string|null}>
     */
    private function readiness(Event $event, bool $hasActiveGateway, callable $can): array
    {
        $items = [];

        $ticketTypes = $event->ticketTypes()->where('is_active', true)->count();
        $items[] = [
            'key' => 'tickets',
            'label' => $event->isFree() && $ticketTypes === 0 ? 'Free to attend' : 'Tickets on sale',
            'done' => $event->isFree() || $ticketTypes > 0 || $event->ticket_price > 0,
            'detail' => $ticketTypes > 0 ? $ticketTypes.' ticket '.($ticketTypes === 1 ? 'type' : 'types') : 'no ticket types',
            'section' => $can('tickets') ? 'tickets' : null,
        ];

        if (! $event->isFree()) {
            $items[] = [
                'key' => 'gateway',
                'label' => 'Payment gateway connected',
                'done' => $hasActiveGateway,
                'detail' => $hasActiveGateway ? 'ready to take payments' : 'needed before selling tickets',
                'section' => null,
            ];
        }

        $sessions = $event->sessions()->count();
        $items[] = [
            'key' => 'schedule',
            'label' => 'Schedule published',
            'done' => $sessions > 0,
            'detail' => $sessions > 0 ? $sessions.' '.($sessions === 1 ? 'session' : 'sessions') : 'no sessions yet',
            'section' => $can('schedule') ? 'schedule' : null,
        ];

        if ($can('automations')) {
            $rules = EventNotificationRule::query()->where('event_id', $event->id)->count();
            $items[] = [
                'key' => 'reminders',
                'label' => 'Reminders set up',
                'done' => $rules > 0,
                'detail' => $rules > 0 ? $rules.' '.($rules === 1 ? 'reminder' : 'reminders') : 'attendees get no reminder',
                'section' => 'automations',
            ];
        }

        return $items;
    }

    /**
     * @param  callable(string): bool  $can
     * @return list<array{key: string, label: string, done: bool, detail: string, section: string|null}>
     */
    private function wrapUp(Event $event, callable $can): array
    {
        $items = [];
        $attended = $this->attendedCount($event);

        if ($can('certificates')) {
            $issued = EventCertificate::query()
                ->where('event_id', $event->id)
                ->whereNotNull('issued_at')
                ->count();

            $items[] = [
                'key' => 'certificates',
                'label' => 'Certificates issued',
                'done' => $issued > 0,
                'detail' => $issued > 0 ? $issued.' issued' : $attended.' attended and could receive one',
                'section' => 'certificates',
            ];
        }

        if ($can('materials')) {
            $unreleased = $event->materials()
                ->where(fn ($query) => $query->whereNull('release_at')->orWhere('release_at', '>', now()))
                ->count();

            $items[] = [
                'key' => 'materials',
                'label' => 'Materials released',
                'done' => $unreleased === 0,
                'detail' => $unreleased === 0 ? 'nothing waiting' : $unreleased.' still held back',
                'section' => 'materials',
            ];
        }

        if ($can('reports')) {
            $items[] = [
                'key' => 'reports',
                'label' => 'Export what happened',
                'done' => false,
                'detail' => 'registrations, check-ins, polls and forum',
                'section' => 'reports',
            ];
        }

        return $items;
    }

    private function attendedCount(Event $event): int
    {
        return $event->registrations()->whereNotNull('checked_in_at')->count();
    }

    /**
     * Everyone holding a place: confirmed plus those already through the door.
     */
    private function expectedCount(Event $event): int
    {
        return $event->registrations()
            ->whereIn('status', [EventRegistration::STATUS_CONFIRMED, EventRegistration::STATUS_CHECKED_IN])
            ->count();
    }
}
