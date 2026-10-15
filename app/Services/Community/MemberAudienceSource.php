<?php

declare(strict_types=1);

namespace App\Services\Community;

use App\Enum\MemberStatusEnum;
use App\Models\Member;
use App\Models\Tenant;
use App\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Builder;

final class MemberAudienceSource implements AudienceSource
{
    /** @param array<string, mixed> $selector
     * @return iterable<AudienceAddress>
     */
    public function addresses(Tenant $tenant, array $selector, string $channel): iterable
    {
        $column = $channel === 'email' ? 'email' : 'phone';
        $query = $this->query($tenant, $selector)->when(isset($selector['member_ids']), fn ($query) => $query->whereIn('id', $selector['member_ids']));
        foreach ($query->select(['id', $column])->lazyById(100) as $member) {
            if (filled($member->{$column})) {
                yield new AudienceAddress($member->{$column}, $channel);
            }
        }
    }

    /** @param array<string, mixed> $selector
     * @return Builder<Member>
     */
    public function query(Tenant $tenant, array $selector): Builder
    {
        return Member::withoutGlobalScope(TenantScope::class)->where('tenant_id', $tenant->id)
            ->where('status', $selector['status'] ?? MemberStatusEnum::Active->value)
            ->when(isset($selector['as_of']), fn ($query) => $query->where('created_at', '<=', $selector['as_of']))
            ->when(($selector['kind'] ?? '') === 'groups', fn ($query) => $query->whereHas('groups', fn ($groups) => $groups->where('community_groups.tenant_id', $tenant->id)->whereNull('archived_at')->whereIn('community_groups.id', $selector['group_ids'])))
            ->when(($selector['kind'] ?? '') === 'arrears', fn ($query) => $query->whereHas('charges', fn ($charges) => $charges->where('tenant_id', $tenant->id)->where('status', 'open')->whereDate('due_on', '<', now(\App\Models\CommunitySettings::for($tenant)->timezone)->toDateString())));
    }

    /** @param array<string, mixed> $selector */
    public function contains(Tenant $tenant, array $selector, string $channel, string $address): bool
    {
        return $this->query($tenant, $selector)->where($channel === 'email' ? 'email' : 'phone', $address)->exists();
    }
}
