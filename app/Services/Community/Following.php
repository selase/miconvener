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
        if ($channel === 'sms') {
            return $this->addSms($tenant, $session, $resumeMarketing);
        }
        $address = $this->address($session, $channel) ?? throw new AuthorizationException('Verify email to follow this organisation.');
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
        $email = $this->address($session, 'email');
        if ($email === null) {
            return false;
        }
        $parent = CommunityFollower::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('channel', 'email')->where('address_hash', $this->preferences->hash('email', $email))->whereNull('unfollowed_at')->first();
        if ($parent === null || ! $this->preferences->allowsMarketing($tenant, 'email', $email)) {
            return false;
        }
        if ($channel === 'email') {
            return true;
        }
        $phone = $this->address($session, 'sms');

        return $phone !== null && $this->preferences->allowsMarketing($tenant, 'sms', $phone) && ! app(WrongNumberReports::class)->isBlocked($tenant, 'sms', $phone) && $parent->channels()->where('address_hash', $this->preferences->hash('sms', $phone))->whereNull('disabled_at')->where('consented_at', '>=', $parent->consented_at)->exists();
    }

    private function addSms(Tenant $tenant, Session $session, bool $resumeMarketing): CommunityFollower
    {
        $email = $this->address($session, 'email') ?? throw new AuthorizationException('Verify email to follow before adding SMS.');
        $phone = $this->address($session, 'sms') ?? throw new AuthorizationException('Verify this phone before adding SMS.');
        $emailHash = $this->preferences->hash('email', $email);
        $phoneHash = $this->preferences->hash('sms', $phone);
        $connection = $tenant->getConnection();

        return $connection->transaction(function () use ($connection, $tenant, $emailHash, $email, $phone, $phoneHash, $resumeMarketing): CommunityFollower {
            $connection->select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ['community-follow:'.$tenant->id.':email:'.$emailHash]);
            $connection->select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ['community-follow:'.$tenant->id.':sms:'.$phoneHash]);
            $parent = CommunityFollower::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('channel', 'email')->where('address_hash', $emailHash)->whereNull('unfollowed_at')->first();
            if ($parent === null || ! $this->preferences->allowsMarketing($tenant, 'email', $email)) {
                throw new AuthorizationException('Follow with your verified email before adding SMS.');
            }
            if (app(WrongNumberReports::class)->isBlocked($tenant, 'sms', $phone)) {
                throw ValidationException::withMessages(['channel' => 'This number needs independent review before SMS announcements can resume.']);
            }
            if (! $this->preferences->allowsMarketing($tenant, 'sms', $phone)) {
                if (! $resumeMarketing) {
                    throw ValidationException::withMessages(['resume_marketing' => 'Confirm you want SMS announcements again.']);
                }
                $this->preferences->enableMarketing($tenant, 'sms', $phone);
            }
            $parent->channels()->updateOrCreate(['channel' => 'sms'], ['tenant_id' => $tenant->id, 'address' => $phone, 'address_hash' => $phoneHash, 'consented_at' => now(), 'disabled_at' => null]);
            CommunityAccessAudit::query()->create(['tenant_id' => $tenant->id, 'action' => 'following_sms_added', 'method' => 'verified_email_and_sms', 'reason' => 'Explicit SMS opt-in for address digest '.$phoneHash]);

            return $parent;
        });
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
