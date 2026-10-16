<?php

declare(strict_types=1);

namespace App\Services\Community;

use App\Enum\TenantStatusEnum;
use App\Models\CommunityAccessAudit;
use App\Models\CommunityFollower;
use App\Models\Event;
use App\Models\Tenant;
use App\Scopes\TenantScope;
use App\Services\Events\PlatformAttendeeVerification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Session\Session;
use Illuminate\Validation\ValidationException;

final class Following
{
    public function __construct(private readonly PlatformAttendeeVerification $emails, private readonly PhoneVerification $phones, private readonly MessagingPreferences $preferences) {}

    public function follow(Tenant $tenant, Session $session, string $channel, bool $resumeMarketing, ?Event $source = null): CommunityFollower
    {
        abort_unless($tenant->followingEnabled() && $tenant->status === TenantStatusEnum::ACTIVE, 404);
        if ($source !== null) {
            abort_unless($source->tenant_id === $tenant->id && $source->status === Event::STATUS_PUBLISHED, 404);
        }
        $address = $this->address($session, $channel) ?? throw new AuthorizationException('Verify this contact first.');
        $hash = $this->preferences->hash($channel, $address);
        $connection = (new CommunityFollower)->getConnection();

        return $connection->transaction(function () use ($connection, $tenant, $channel, $address, $hash, $resumeMarketing, $source): CommunityFollower {
            $connection->select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ['community-follow:'.$tenant->id.':'.$channel.':'.$hash]);
            if ($channel === 'sms' && app(WrongNumberReports::class)->isBlocked($tenant, $channel, $address)) {
                throw ValidationException::withMessages(['channel' => 'This number needs an independent review by the organisation before SMS announcements can resume. You may use a separately verified email.']);
            }
            if (! $this->preferences->allowsMarketing($tenant, $channel, $address)) {
                if (! $resumeMarketing) {
                    throw ValidationException::withMessages(['resume_marketing' => 'Confirm that you want to receive announcements at this address again.']);
                }
                $this->preferences->enableMarketing($tenant, $channel, $address);
            }
            $row = CommunityFollower::withoutGlobalScope(TenantScope::class)->where('tenant_id', $tenant->id)->where('channel', $channel)->where('address_hash', $hash)->first();
            if ($row !== null && $row->unfollowed_at === null) {
                return $row;
            }
            $row ??= new CommunityFollower;
            $row->fill(['tenant_id' => $tenant->id, 'channel' => $channel, 'address' => $address, 'address_hash' => $hash, 'consented_at' => now(), 'consent_source' => $source === null ? 'organisation' : 'event', 'source_event_id' => $source?->id, 'unfollowed_at' => null])->save();
            CommunityAccessAudit::query()->create(['tenant_id' => $tenant->id, 'action' => 'following_consented', 'method' => 'verified_'.$channel, 'reason' => 'Explicit opt-in for address digest '.$hash]);

            return $row;
        });
    }

    public function unfollow(Tenant $tenant, Session $session, string $channel): void
    {
        $address = $this->address($session, $channel) ?? throw new AuthorizationException('Verify this contact first.');
        $this->preferences->disableMarketing($tenant, $channel, $address);
    }

    public function isFollowing(Tenant $tenant, Session $session, string $channel): bool
    {
        $address = $this->address($session, $channel);

        return $address !== null && $this->preferences->allowsMarketing($tenant, $channel, $address)
            && CommunityFollower::withoutGlobalScope(TenantScope::class)->where('tenant_id', $tenant->id)->where('channel', $channel)->where('address_hash', $this->preferences->hash($channel, $address))->whereNull('unfollowed_at')->exists();
    }

    private function address(Session $session, string $channel): ?string
    {
        if (! in_array($channel, ['email', 'sms'], true)) {
            throw ValidationException::withMessages(['channel' => 'Choose email or SMS.']);
        }
        $address = $channel === 'email' ? $this->emails->verifiedEmail($session) : $this->phones->verifiedPhone($session);

        return $address === null ? null : $this->preferences->normalise($channel, $address);
    }
}
