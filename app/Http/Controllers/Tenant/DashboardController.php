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
        $focusEvent = $liveEvent ?? $this->nextEvent($tenant);

        return Inertia::render('Tenant/Dashboard', [
            'checklist' => [
                'onboarding' => (bool) $tenant->onboarding_completed_at,
                'team' => $tenant->users()->count() > 1,
                'branding' => (! empty($tenant->logo)
                    || ! empty(data_get($tenant->meta, 'branding.primary_color'))
                    || ! empty(data_get($tenant->meta, 'primary_color'))),
            ],
            'isLive' => $liveEvent !== null,
            'focusEvent' => $focusEvent ? $this->focusPayload($focusEvent, $liveEvent !== null) : null,
            'money' => $this->money($tenant),
            'arrivals' => $liveEvent ? $this->arrivals($liveEvent) : [],
            'registrationTrend' => $focusEvent ? $this->registrationTrend($focusEvent) : [],
            'needsAPerson' => $focusEvent ? $this->needsAPerson($focusEvent) : [],
            'upcoming' => $this->upcoming($tenant, $focusEvent?->id),
            'links' => [
                'branding' => route('tenant.settings.index'),
                'team' => route('tenant.users.index'),
                'finishOnboarding' => route('tenant.onboarding.finish'),
                'events' => route('tenant.events.index'),
                'finance' => route('tenant.finance.index'),
                'event' => $focusEvent ? route('tenant.events.show', ['subdomain' => $tenant->slug, 'event' => $focusEvent->id]) : null,
            ],
            'currency' => $focusEvent?->currency ?? config('services.paystack.currency', 'GHS'),
        ]);
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
     * @return list<array{label: string, count: int}>
     */
    private function arrivals(Event $event): array
    {
        $checkIns = EventRegistration::query()
            ->where('event_id', $event->id)
            ->whereNotNull('checked_in_at')
            ->pluck('checked_in_at');

        if ($checkIns->isEmpty()) {
            return [];
        }

        $start = CarbonImmutable::parse($checkIns->min())->timezone($event->timezone)->startOfHour();
        $end = CarbonImmutable::parse($checkIns->max())->timezone($event->timezone);

        $buckets = [];
        for ($cursor = $start; $cursor <= $end; $cursor = $cursor->addMinutes(30)) {
            $buckets[$cursor->format('H:i')] = 0;
        }

        foreach ($checkIns as $at) {
            $slot = CarbonImmutable::parse($at)->timezone($event->timezone);
            $slot = $slot->minute < 30 ? $slot->startOfHour() : $slot->startOfHour()->addMinutes(30);
            $key = $slot->format('H:i');
            if (array_key_exists($key, $buckets)) {
                $buckets[$key]++;
            }
        }

        return collect($buckets)
            ->map(fn (int $count, string $label): array => ['label' => $label, 'count' => $count])
            ->values()
            ->all();
    }

    /**
     * Registrations per day since the event opened. The shape of this curve is
     * what tells a host whether to push harder on marketing.
     *
     * @return list<array{label: string, count: int}>
     */
    private function registrationTrend(Event $event): array
    {
        $since = CarbonImmutable::now()->subDays(29)->startOfDay();

        $counts = EventRegistration::query()
            ->where('event_id', $event->id)
            ->where('created_at', '>=', $since)
            ->get(['created_at'])
            ->groupBy(fn ($row): string => CarbonImmutable::parse($row->created_at)->format('Y-m-d'))
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

    /**
     * @return list<array<string, mixed>>
     */
    private function upcoming(Tenant $tenant, ?string $excludeId): array
    {
        return Event::query()
            ->where('tenant_id', $tenant->id)
            ->where('status', '!=', Event::STATUS_CANCELLED)
            ->where('ends_at', '>=', now())
            ->when($excludeId, fn ($q) => $q->where('id', '!=', $excludeId))
            ->withCount(['registrations as confirmed_count' => fn ($q) => $q->confirmed()])
            ->orderBy('starts_at')
            ->limit(4)
            ->get()
            ->map(fn (Event $e): array => [
                'id' => $e->id,
                'name' => $e->name,
                'starts_at' => $e->starts_at?->toIso8601String(),
                'timezone' => $e->timezone,
                'capacity' => $e->capacity,
                'confirmed' => (int) $e->confirmed_count,
                'status' => $e->status,
            ])
            ->all();
    }
}
