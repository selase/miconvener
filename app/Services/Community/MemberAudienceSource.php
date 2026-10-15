<?php

declare(strict_types=1);

namespace App\Services\Community;

use App\Enum\MemberStatusEnum;
use App\Models\Member;
use App\Models\Tenant;
use App\Scopes\TenantScope;

final class MemberAudienceSource implements AudienceSource
{
    /** @param array<string, mixed> $selector
     * @return iterable<AudienceAddress>
     */
    public function addresses(Tenant $tenant, array $selector, string $channel): iterable
    {
        $column = $channel === 'email' ? 'email' : 'phone';
        $query = Member::withoutGlobalScope(TenantScope::class)->where('tenant_id', $tenant->id)
            ->where('status', $selector['status'] ?? MemberStatusEnum::Active->value)
            ->when(($selector['kind'] ?? '') === 'groups', fn ($query) => $query->whereHas('groups', fn ($groups) => $groups->where('community_groups.tenant_id', $tenant->id)->whereNull('archived_at')->whereIn('community_groups.id', $selector['group_ids'])))
            ->when(($selector['kind'] ?? '') === 'arrears', fn ($query) => $query->whereHas('charges', fn ($charges) => $charges->where('tenant_id', $tenant->id)->where('status', 'open')->whereDate('due_on', '<', now(\App\Models\CommunitySettings::for($tenant)->timezone)->toDateString())));
        foreach ($query->select(['id', $column])->lazyById(100) as $member) {
            if (filled($member->{$column})) {
                yield new AudienceAddress($member->{$column}, $channel);
            }
        }
    }
}
