<?php

declare(strict_types=1);

namespace App\Services\Community;

use App\Models\CommunityFollower;
use App\Models\Tenant;
use App\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Builder;

final class FollowerAudienceSource implements AudienceSource
{
    /** @param array<string,mixed> $selector
     * @return Builder<CommunityFollower>
     */
    public function query(Tenant $tenant, array $selector): Builder
    {
        return CommunityFollower::withoutGlobalScope(TenantScope::class)->where('tenant_id', $tenant->id)->whereNull('unfollowed_at')
            ->when(! $tenant->followingEnabled() || ! in_array($selector['kind'] ?? '', ['followers', 'members_and_followers'], true), fn ($query) => $query->whereRaw('1=0'))
            ->when(isset($selector['as_of']), fn ($query) => $query->where('consented_at', '<=', \Carbon\CarbonImmutable::parse($selector['as_of'])->format('Y-m-d H:i:s.uP')))
            ->when(isset($selector['follower_ids']), fn ($query) => $query->whereIn('id', $selector['follower_ids']));
    }

    /** @param array<string,mixed> $selector
     * @return iterable<AudienceAddress>
     */
    public function addresses(Tenant $tenant, array $selector, string $channel): iterable
    {
        foreach ($this->query($tenant, $selector)->where('channel', $channel)->lazyById(100) as $follower) {
            yield new AudienceAddress($follower->address, $channel);
        }
    }

    /** @param array<string,mixed> $selector */
    public function contains(Tenant $tenant, array $selector, string $channel, string $address): bool
    {
        $preferences = app(MessagingPreferences::class);

        return $preferences->allowsMarketing($tenant, $channel, $address)
            && $this->query($tenant, $selector)->where('channel', $channel)->where('address_hash', $preferences->hash($channel, $address))->exists();
    }
}
