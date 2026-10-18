<?php

declare(strict_types=1);

namespace App\Services\Community;

use App\Models\CommunitySettings;
use App\Models\Event;
use App\Models\EventSession;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

final readonly class CommunityServices
{
    public function __construct(private CommunityContentAccess $access) {}

    /** @param array<string,mixed> $filters
     * @return LengthAwarePaginator<int,EventSession>
     */
    public function entries(Tenant $tenant, Session $session, array $filters): LengthAwarePaginator
    {
        $timezone = CommunitySettings::for($tenant)->timezone;
        $notes = $this->access->visibleItems($session, $tenant)->where('kind', 'service_note');

        return $this->occurrences($tenant)->whereIn('id', (clone $notes)->select('session_id'))
            ->when(! empty($filters['event']), fn ($q) => $q->where('event_id', $filters['event']))
            ->when(! empty($filters['from']), fn ($q) => $q->where('starts_at', '>=', CarbonImmutable::parse($filters['from'], $timezone)->startOfDay()->utc()))
            ->when(! empty($filters['until']), fn ($q) => $q->where('starts_at', '<=', CarbonImmutable::parse($filters['until'], $timezone)->endOfDay()->utc()))
            ->with(['event' => fn ($event) => $event->withTrashed()])->withCount(['communityNotes' => fn ($q) => $q->whereIn('id', (clone $notes)->select('id'))])
            ->orderByDesc('starts_at')->orderByDesc('id')->paginate(20);
    }

    /** @return Builder<EventSession> */
    public function occurrences(Tenant $tenant): Builder
    {
        return EventSession::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('is_occurrence', true)
            ->where(fn ($q) => $q->whereNull('occurrence_status')->orWhere('occurrence_status', '!=', EventSession::STATUS_CANCELLED))
            ->whereIn('event_id', Event::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('status', Event::STATUS_PUBLISHED)->select('id'));
    }

    /** @return array<string,mixed> */
    public function payload(Tenant $tenant, EventSession $session): array
    {
        $timezone = CommunitySettings::for($tenant)->timezone;
        $event = Event::withoutGlobalScopes()->where('tenant_id', $tenant->id)->findOrFail($session->event_id);

        return ['id' => $session->id, 'event_id' => $event->id, 'event_title' => $event->title, 'title' => $session->title, 'starts_at' => $session->starts_at->toImmutable()->setTimezone($timezone)->toIso8601String(), 'ends_at' => $session->ends_at?->toImmutable()->setTimezone($timezone)->toIso8601String(), 'timezone' => $timezone, 'status' => $session->occurrence_status ?? 'scheduled', 'notes_count' => $session->getAttribute('community_notes_count')];
    }
}
