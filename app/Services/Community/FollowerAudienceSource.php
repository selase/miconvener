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
        return CommunityFollower::withoutGlobalScope(TenantScope::class)->where('tenant_id', $tenant->id)->where('channel', 'email')->whereNull('unfollowed_at')
            ->when(! $tenant->followingEnabled() || ! in_array($selector['kind'] ?? '', ['followers', 'members_and_followers'], true), fn ($query) => $query->whereRaw('1=0'))
            ->when(isset($selector['as_of']), fn ($query) => $query->where('consented_at', '<=', \Carbon\CarbonImmutable::parse($selector['as_of'])->format('Y-m-d H:i:s.uP')))
            ->when(isset($selector['follower_ids']), fn ($query) => $query->whereIn('id', $selector['follower_ids']));
    }

    /** @param array<string,mixed> $selector
     * @return iterable<AudienceAddress>
     */
    public function addresses(Tenant $tenant, array $selector, string $channel): iterable
    {
        foreach ($this->query($tenant, $selector)->with('channels')->lazyById(100) as $follower) {
            if (! app(MessagingPreferences::class)->allowsMarketing($tenant, 'email', $follower->address)) {
                continue;
            }
            if ($channel === 'email') {
                yield new AudienceAddress($follower->address, 'email');

                continue;
            }
            foreach ($follower->channels as $added) {
                if ($added->disabled_at === null && $added->consented_at->gte($follower->consented_at) && ! app(WrongNumberReports::class)->isBlocked($tenant, 'sms', $added->address)) {
                    yield new AudienceAddress($added->address, 'sms');
                }
            }
        }
    }

    /** @param array<string,mixed> $selector */
    public function contains(Tenant $tenant, array $selector, string $channel, string $address): bool
    {
        $preferences = app(MessagingPreferences::class);
        if (! $preferences->allowsMarketing($tenant, $channel, $address)) {
            return false;
        }
        $hash = $preferences->hash($channel, $address);
        $query = $this->query($tenant, $selector);
        if ($channel === 'email') {
            return $query->where('address_hash', $hash)->exists();
        }
        if (app(WrongNumberReports::class)->isBlocked($tenant, 'sms', $address)) {
            return false;
        }
        foreach ($query->whereHas('channels', fn ($q) => $q->where('address_hash', $hash)->whereNull('disabled_at')->whereColumn('community_follower_channels.consented_at', '>=', 'community_followers.consented_at'))->cursor() as $parent) {
            if ($preferences->allowsMarketing($tenant, 'email', $parent->address)) {
                return true;
            }
        }

        return false;
    }
}
