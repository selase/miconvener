<?php

declare(strict_types=1);

namespace App\Services\Community;

use App\Models\CommunityLibraryItem;
use App\Models\Member;
use App\Models\Tenant;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\Eloquent\Collection;

final readonly class CommunityContentAccess
{
    public function __construct(private CommunityHistory $history, private MemberAccess $members, private MemberAudienceSource $audiences, private CommunityLibraryRollout $rollout) {}

    /** @param array<string,mixed> $selector
     * @return Collection<int,Member>
     */
    public function readers(Session $session, Tenant $tenant, array $selector): Collection
    {
        $kind = $selector['kind'] ?? null;
        if (! in_array($kind, ['all_members', 'groups'], true) || array_diff(array_keys($selector), ['kind', 'group_ids']) !== []
            || ($kind === 'groups' && (! is_array($selector['group_ids'] ?? null) || $selector['group_ids'] === []))) {
            return new Collection();
        }

        return $this->audiences->query($tenant, $selector)->whereIn('id', $this->history->memberQuery($session)->select('id'))->orderBy('id')->get()
            ->filter(fn (Member $member): bool => $this->members->role($session, $member) !== null)->values();
    }

    /** @return \Illuminate\Database\Eloquent\Builder<CommunityLibraryItem> */
    public function visibleItems(Session $session, Tenant $tenant): \Illuminate\Database\Eloquent\Builder
    {
        $readers = $this->readers($session, $tenant, ['kind' => 'all_members']);
        $groupIds = $readers->load(['groups' => fn ($q) => $q->where('community_groups.tenant_id', $tenant->id)->whereNull('archived_at')])->flatMap(fn ($member) => $member->groups->whereNull('archived_at')->pluck('id'))->unique()->values()->all();

        return CommunityLibraryItem::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('state', 'published')
            ->whereRaw("(audience - 'kind' - 'group_ids') = '{}'::jsonb")
            ->where(fn ($q) => $q->whereNull('release_at')->orWhere('release_at', '<=', now()))
            ->when($readers->isEmpty(), fn ($q) => $q->whereRaw('1=0'))
            ->where(function ($q) use ($groupIds): void {
                $q->where('audience->kind', 'all_members');
                foreach ($groupIds as $id) {
                    $q->orWhere(fn ($groups) => $groups->where('audience->kind', 'groups')->whereRaw("jsonb_typeof(audience->'group_ids') = 'array'")->whereJsonContains('audience->group_ids', $id));
                }
            });
    }

    public function canRead(Session $session, CommunityLibraryItem $item): bool
    {
        return $this->readable($session, $item) !== null;
    }

    /** Return the very snapshot whose audience and publication were authorised. */
    public function readable(Session $session, CommunityLibraryItem $item): ?CommunityLibraryItem
    {
        $current = CommunityLibraryItem::withoutGlobalScopes()->where('tenant_id', $item->tenant_id)->find($item->id);
        $tenant = Tenant::query()->find($item->tenant_id);
        if ($current === null || $tenant === null || ! $this->rollout->available($tenant) || $current->state !== 'published' || $current->release_at?->isFuture()) {
            return null;
        }

        if ($current->kind === 'service_note' && ! app(CommunityServices::class)->occurrences($tenant)->where('event_id', $current->event_id)->whereKey($current->session_id)->exists()) {
            return null;
        }

        return $this->readers($session, $tenant, $current->audience)->isNotEmpty() ? $current : null;
    }
}
