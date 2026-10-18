<?php

declare(strict_types=1);

namespace App\Services\Community;

use App\Models\CommunityLibraryItem;
use App\Models\Member;
use App\Models\Tenant;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Collection;

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
            return collect();
        }

        return $this->audiences->query($tenant, $selector)->whereIn('id', $this->history->memberQuery($session)->select('id'))->orderBy('id')->get()
            ->filter(fn (Member $member): bool => $this->members->role($session, $member) !== null)->values();
    }

    public function canRead(Session $session, CommunityLibraryItem $item): bool
    {
        $current = CommunityLibraryItem::withoutGlobalScopes()->where('tenant_id', $item->tenant_id)->find($item->id);
        $tenant = Tenant::query()->find($item->tenant_id);
        if ($current === null || $tenant === null || ! $this->rollout->available($tenant) || $current->state !== 'published' || $current->release_at?->isFuture()) {
            return false;
        }

        return $this->readers($session, $tenant, $current->audience)->isNotEmpty();
    }
}
