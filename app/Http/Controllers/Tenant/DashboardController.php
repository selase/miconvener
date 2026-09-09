<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventLedgerEntry;
use App\Models\EventRegistration;
use App\Models\EventServiceRequest;
use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Inertia\Inertia;
use Inertia\Response;

final class DashboardController extends Controller
{
    public function index(TenantContext $tenantContext): Response
    {
        $tenant = $tenantContext->getTenant();

        // The dashboard answers a different question depending on the day. While
        // an event is running the only thing that matters is the room: who is
        // through the door and what is going wrong. Otherwise it is the next
        // event and the money.
        $liveEvent = $this->liveEvent($tenant);
        $nextEvent = $liveEvent === null ? $this->nextEvent($tenant) : null;

        return Inertia::render('Tenant/Dashboard', [
            'checklist' => [
                'onboarding' => (bool) $tenant->onboarding_completed_at,
                'team' => $tenant->users()->count() > 1,
                'branding' => (! empty($tenant->logo)
                    || ! empty(data_get($tenant->meta, 'branding.primary_color'))
                    || ! empty(data_get($tenant->meta, 'primary_color'))),
            ],
            'totals' => $this->totals($tenant),
            'money' => $this->money($tenant),
            // The event-day view earns its place only while an event is running.
            'liveEvent' => $liveEvent ? $this->focusPayload($liveEvent, true) : null,
            'nextEvent' => $liveEvent === null && $nextEvent ? $this->focusPayload($nextEvent, false) : null,
            'arrivals' => $liveEvent ? $this->arrivals($liveEvent) : ['step' => 0, 'series' => []],
            'needsAPerson' => $liveEvent ? $this->needsAPerson($liveEvent) : [],
            'registrationTrend' => $this->registrationTrend($tenant),
            'events' => $this->eventList($tenant),
            'links' => [
                'branding' => route('tenant.settings.index'),
                'team' => route('tenant.users.index'),
                'finishOnboarding' => route('tenant.onboarding.finish'),
                'events' => route('tenant.events.index'),
                'finance' => route('tenant.finance.index'),
                'liveEvent' => $liveEvent ? route('tenant.events.show', ['subdomain' => $tenant->slug, 'event' => $liveEvent->id]) : null,
                'nextEvent' => $liveEvent === null && $nextEvent ? route('tenant.events.show', ['subdomain' => $tenant->slug, 'event' => $nextEvent->id]) : null,
            ],
            'currency' => config('services.paystack.currency', 'GHS'),
        ]);
    }

    /**
     * Everything the tenant runs, not just the event in front of them.
     *
     * @return array<string, int>
     */
    private function totals(Tenant $tenant): array
    {
        $eventIds = Event::query()->where('tenant_id', $tenant->id)->toBase()->pluck('id');

        return [
            'events' => $eventIds->count(),
            'upcoming_events' => Event::query()
                ->where('tenant_id', $tenant->id)
                ->where('status', '!=', Event::STATUS_CANCELLED)
                ->where('ends_at', '>=', now())
                ->count(),
            'registrations' => EventRegistration::query()
                ->whereIn('event_id', $eventIds)
                ->confirmed()
                ->count(),
            'checked_in' => EventRegistration::query()
                ->whereIn('event_id', $eventIds)
                ->where('status', EventRegistration::STATUS_CHECKED_IN)
                ->count(),
        ];
    }

    /**
     * The tenant's events, soonest upcoming first and then most recent past, so
     * the row someone wants is near the top whichever way they are looking.
     *
     * @return list<array<string, mixed>>
     */
    private function eventList(Tenant $tenant, int $limit = 20): array
    {
        $base = fn () => Event::query()
            ->where('tenant_id', $tenant->id)
            ->withCount([
                'registrations as registered_count' => fn ($q) => $q->whereNotIn('status', [
                    EventRegistration::STATUS_CANCELLED,
                    EventRegistration::STATUS_REJECTED,
                ]),
                'registrations as checked_in_count' => fn ($q) => $q->where('status', EventRegistration::STATUS_CHECKED_IN),
            ]);

        $upcoming = $base()->where('ends_at', '>=', now())->orderBy('starts_at')->limit($limit)->get();
        $past = $base()->where('ends_at', '<', now())->orderByDesc('starts_at')->limit($limit)->get();

        $events = $upcoming->concat($past)->take($limit);

        // One grouped query for the money rather than one per row.
        $collected = EventLedgerEntry::query()
            ->whereIn('event_id', $events->pluck('id'))
            ->where('type', EventLedgerEntry::TYPE_CHARGE)
            ->selectRaw('event_id, SUM(gross_amount) AS gross')
            ->groupBy('event_id')
            ->pluck('gross', 'event_id');

        return $events->map(fn (Event $e): array => [
            'id' => $e->id,
            'name' => $e->name,
            'status' => $e->status,
            'starts_at' => $e->starts_at?->toIso8601String(),
            'timezone' => $e->timezone,
            'is_past' => $e->ends_at?->isPast() ?? false,
            'is_live' => $e->starts_at?->isPast() && $e->ends_at?->isFuture(),
            'capacity' => $e->capacity,
            'registered' => (int) $e->registered_count,
            'checked_in' => (int) $e->checked_in_count,
            'collected' => (int) ($collected[$e->id] ?? 0),
            'url' => route('tenant.events.show', ['subdomain' => $tenant->slug, 'event' => $e->id]),
        ])->values()->all();
    }

    private function liveEvent(Tenant $tenant): ?Event
    {
        return Event::query()
            ->where('tenant_id', $tenant->id)
            ->where('status', Event::STATUS_PUBLISHED)
            ->where('starts_at', '<=', now())
            ->where('ends_at', '>=', now())
            ->orderBy('starts_at')
            ->first();
    }

    private function nextEvent(Tenant $tenant): ?Event
    {
        return Event::query()
            ->where('tenant_id', $tenant->id)
            ->where('status', '!=', Event::STATUS_CANCELLED)
            ->where('ends_at', '>=', now())
            ->orderBy('starts_at')
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function focusPayload(Event $event, bool $isLive): array
    {
        $registrations = EventRegistration::query()->where('event_id', $event->id);

        $registered = (clone $registrations)->whereNotIn('status', [
            EventRegistration::STATUS_CANCELLED,
            EventRegistration::STATUS_REJECTED,
        ])->count();

        $confirmed = (clone $registrations)->confirmed()->count();
        $awaitingPayment = (clone $registrations)->where('status', EventRegistration::STATUS_PENDING_PAYMENT)->count();
        $checkedIn = (clone $registrations)->where('status', EventRegistration::STATUS_CHECKED_IN)->count();

        return [
            'id' => $event->id,
            'name' => $event->name,
            'slug' => $event->slug,
            'starts_at' => $event->starts_at?->toIso8601String(),
            'ends_at' => $event->ends_at?->toIso8601String(),
            'timezone' => $event->timezone,
            'days_until' => $isLive ? 0 : (int) max(0, CarbonImmutable::now()->startOfDay()->diffInDays($event->starts_at->startOfDay(), false)),
            'capacity' => $event->capacity,
            'registered' => $registered,
            'confirmed' => $confirmed,
            'awaiting_payment' => $awaitingPayment,
            'checked_in' => $checkedIn,
            'check_in_rate' => $confirmed > 0 ? (int) round($checkedIn / $confirmed * 100) : 0,
        ];
    }

    /**
     * Money as the ledger records it, which is the only place it is settled
     * rather than estimated.
     *
     * @return array<string, int>
     */
    private function money(Tenant $tenant): array
    {
        $rows = EventLedgerEntry::query()
            ->where('tenant_id', $tenant->id)
            ->selectRaw('type, SUM(gross_amount) AS gross, SUM(commission_amount) AS commission, SUM(net_amount) AS net')
            ->groupBy('type')
            ->get()
            ->keyBy('type');

        $charged = (int) ($rows[EventLedgerEntry::TYPE_CHARGE]->gross ?? 0);
        $refunded = (int) ($rows[EventLedgerEntry::TYPE_REFUND]->gross ?? 0);
        $paidOut = (int) ($rows[EventLedgerEntry::TYPE_PAYOUT]->gross ?? 0);
        $netToYou = (int) ($rows[EventLedgerEntry::TYPE_CHARGE]->net ?? 0) + (int) ($rows[EventLedgerEntry::TYPE_REFUND]->net ?? 0);

        return [
            'collected' => $charged,
            'refunded' => $refunded,
            'commission' => (int) ($rows[EventLedgerEntry::TYPE_CHARGE]->commission ?? 0),
            'settles_to_you' => $netToYou,
            'awaiting_payout' => max(0, $netToYou - $paidOut),
        ];
    }

    /**
     * Check-ins bucketed by half hour across the event day, so the shape of the
     * queue at the gate is visible rather than inferred from a single number.
     *
     * @return array{step: int, series: list<array{label: string, count: int}>}
     */
    private function arrivals(Event $event): array
    {
        // toBase() so this reads timestamps rather than hydrating one Eloquent
        // model per attendee -- a full house was pulling ~400 objects into
        // memory to draw a bar chart.
        $checkIns = EventRegistration::query()
            ->where('event_id', $event->id)
            ->whereNotNull('checked_in_at')
            ->toBase()
            ->pluck('checked_in_at');

        if ($checkIns->isEmpty()) {
            return ['step' => 0, 'series' => []];
        }

        $first = CarbonImmutable::parse($checkIns->min())->timezone($event->timezone);
        $last = CarbonImmutable::parse($checkIns->max())->timezone($event->timezone);

        // Bucket width follows the span, so a two-hour door and an all-day one
        // both land near two dozen bars instead of five fat ones.
        $spanMinutes = max(1, (int) $first->diffInMinutes($last));
        $step = collect([5, 10, 15, 30, 60])
            ->first(fn (int $candidate): bool => $spanMinutes / $candidate <= 28) ?? 60;

        $start = $first->startOfHour();
        $buckets = [];
        for ($cursor = $start; $cursor <= $last; $cursor = $cursor->addMinutes($step)) {
            $buckets[$cursor->format('H:i')] = 0;
        }

        foreach ($checkIns as $at) {
            $slot = CarbonImmutable::parse($at)->timezone($event->timezone);
            $minute = (int) floor($slot->minute / $step) * $step;
            $key = $slot->setTime($slot->hour, $minute)->format('H:i');
            if (array_key_exists($key, $buckets)) {
                $buckets[$key]++;
            }
        }

        return [
            'step' => $step,
            'series' => collect($buckets)
                ->map(fn (int $count, string $label): array => ['label' => $label, 'count' => $count])
                ->values()
                ->all(),
        ];
    }

    /**
     * Registrations per day across every event the tenant runs. The shape of
     * this curve is what tells them whether to push harder on marketing.
     *
     * @return list<array{label: string, count: int}>
     */
    private function registrationTrend(Tenant $tenant): array
    {
        $since = CarbonImmutable::now()->subDays(29)->startOfDay();
        $eventIds = Event::query()->where('tenant_id', $tenant->id)->toBase()->pluck('id');

        // Same reason as arrivals(): count dates, do not hydrate a model per
        // registration just to bucket them.
        $counts = EventRegistration::query()
            ->whereIn('event_id', $eventIds)
            ->where('created_at', '>=', $since)
            ->toBase()
            ->pluck('created_at')
            ->groupBy(fn ($at): string => CarbonImmutable::parse($at)->format('Y-m-d'))
            ->map->count();

        $days = [];
        for ($day = $since; $day <= CarbonImmutable::now()->startOfDay(); $day = $day->addDay()) {
            $key = $day->format('Y-m-d');
            $days[] = ['label' => $day->format('j M'), 'count' => (int) ($counts[$key] ?? 0)];
        }

        return $days;
    }

    /**
     * Work waiting on a human, oldest first — open service requests, then the
     * approvals and payments that stall a registration.
     *
     * @return list<array<string, mixed>>
     */
    private function needsAPerson(Event $event): array
    {
        $requests = EventServiceRequest::query()
            ->where('event_id', $event->id)
            ->whereIn('status', [
                EventServiceRequest::STATUS_OPEN,
                EventServiceRequest::STATUS_ACKNOWLEDGED,
                EventServiceRequest::STATUS_IN_PROGRESS,
            ])
            ->with('registration:id,full_name')
            ->orderBy('created_at')
            ->limit(6)
            ->get()
            ->map(fn (EventServiceRequest $r): array => [
                'id' => $r->id,
                'kind' => 'service_request',
                'title' => $r->note ?: ucfirst(str_replace('_', ' ', (string) $r->type)),
                'detail' => collect([$r->location, $r->registration?->full_name ? 'raised by '.$r->registration->full_name : null])
                    ->filter()->implode(', '),
                'waited' => $r->created_at?->diffForHumans(null, true, true),
                'tag' => ucfirst(str_replace('_', ' ', (string) $r->type)),
                'status' => $r->status === EventServiceRequest::STATUS_OPEN ? 'pending' : 'neutral',
            ]);

        $pendingApproval = EventRegistration::query()
            ->where('event_id', $event->id)
            ->where('status', EventRegistration::STATUS_PENDING_APPROVAL)
            ->orderBy('created_at')
            ->limit(4)
            ->get()
            ->map(fn (EventRegistration $r): array => [
                'id' => $r->id,
                'kind' => 'approval',
                'title' => $r->full_name,
                'detail' => 'Waiting for approval · '.$r->email,
                'waited' => $r->created_at?->diffForHumans(null, true, true),
                'tag' => 'Approval',
                'status' => 'pending',
            ]);

        return $requests->concat($pendingApproval)->take(8)->values()->all();
    }
}
