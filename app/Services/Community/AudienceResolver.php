<?php

declare(strict_types=1);

namespace App\Services\Community;

use App\Enum\MemberStatusEnum;
use App\Models\CommunityAudienceAddress;
use App\Models\CommunityGroup;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class AudienceResolver
{
    /** @param list<AudienceSource> $additionalSources */
    public function __construct(private MemberAudienceSource $members, private MessagingPreferences $preferences, private array $additionalSources = []) {}

    /** @param array<string, mixed> $selector
     * @return iterable<AudienceAddress>
     */
    public function resolve(Tenant $tenant, array $selector, string $channel, ?User $actor = null): iterable
    {
        $this->assertValid($tenant, $selector, $channel, $actor);
        $run = (string) Str::uuid();
        try {
            $sources = ($selector['kind'] ?? '') === 'event' ? [app(EventAudienceSource::class)] : [$this->members, app(FollowerAudienceSource::class), ...$this->additionalSources];
            foreach ($sources as $source) {
                $batch = [];
                foreach ($source->addresses($tenant, $selector, $channel) as $candidate) {
                    $address = $this->preferences->normalise($channel, $candidate->address);
                    if ($candidate->channel !== $channel || $address === null || ! $this->preferences->allowsMarketing($tenant, $channel, $address)) {
                        continue;
                    }
                    $batch[] = ['id' => (string) Str::uuid(), 'run_id' => $run, 'tenant_id' => $tenant->id, 'channel' => $channel,
                        'address_hash' => $this->preferences->hash($channel, $address), 'address' => Crypt::encryptString($address),
                        'expires_at' => now()->addHour(), 'created_at' => now(), 'updated_at' => now()];
                    if (count($batch) === 100) {
                        CommunityAudienceAddress::query()->insertOrIgnore($batch);
                        $batch = [];
                    }
                }
                if ($batch !== []) {
                    CommunityAudienceAddress::query()->insertOrIgnore($batch);
                }
            }
            foreach (CommunityAudienceAddress::query()->where('run_id', $run)->where('tenant_id', $tenant->id)->lazyById(100) as $row) {
                yield new AudienceAddress($row->address, $channel);
            }
        } finally {
            CommunityAudienceAddress::query()->where('run_id', $run)->where('tenant_id', $tenant->id)->delete();
        }
    }

    /** @param array<string, mixed> $selector */
    public function assertValid(Tenant $tenant, array $selector, string $channel, ?User $actor): void
    {
        $kind = $selector['kind'] ?? '';
        if (! in_array($channel, ['email', 'sms'], true) || ! in_array($kind, ['all_members', 'status', 'groups', 'arrears', 'event', 'followers', 'members_and_followers'], true)
            || array_diff(array_keys($selector), ['kind', 'status', 'group_ids', 'event_id', 'event_audience', 'member_ids', 'follower_ids', 'as_of']) !== []
            || (isset($selector['status']) && ! in_array($selector['status'], array_column(MemberStatusEnum::cases(), 'value'), true))) {
            throw ValidationException::withMessages(['audience' => 'Choose a valid audience and channel.']);
        }
        if ($kind === 'groups') {
            $ids = $selector['group_ids'] ?? [];
            if (! is_array($ids) || $ids === [] || count($ids) > 100 || count(array_unique($ids)) !== count($ids) || count(array_filter($ids, fn ($id) => is_string($id) && Str::isUuid($id))) !== count($ids)
                || CommunityGroup::query()->where('tenant_id', $tenant->id)->whereNull('archived_at')->whereIn('id', $ids)->count() !== count($ids)) {
                throw ValidationException::withMessages(['audience' => 'Choose active groups in this organisation.']);
            }
        }
        if ($kind === 'arrears') {
            abort_unless($actor !== null && ($actor->tenant_id === $tenant->id || $tenant->users()->where('users.id', $actor->id)->exists()), 403);
            $team = getPermissionsTeamId();
            try {
                setPermissionsTeamId($tenant->id);
                Gate::forUser($actor)->authorize('read finance');
            } finally {
                setPermissionsTeamId($team);
            }
        }
    }
}
