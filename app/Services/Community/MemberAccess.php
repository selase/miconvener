<?php

declare(strict_types=1);

namespace App\Services\Community;

use App\Models\CommunityAccessAudit;
use App\Models\CommunityAccessCredential;
use App\Models\Member;
use App\Models\Tenant;
use App\Models\User;
use App\Scopes\TenantScope;
use App\Services\Events\PlatformAttendeeVerification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

final class MemberAccess
{
    public function __construct(private readonly PhoneVerification $phones, private readonly PlatformAttendeeVerification $emails, private readonly MemberIdentity $identity) {}

    public function canView(Session $session, Member $member): bool
    {
        $member = Member::withoutGlobalScope(TenantScope::class)->where('tenant_id', $member->tenant_id)->find($member->id);
        if ($member === null) {
            return false;
        }
        $email = $this->emails->verifiedEmail($session);
        if ($email !== null && $this->identity->normaliseEmail($email) === $this->identity->normaliseEmail($member->email)) {
            return true;
        }
        $phone = $this->phones->verifiedPhone($session);
        $grants = $session->get('community_member_grants', []);
        if ($phone === null || ! is_array($grants) || $grants === []) {
            return false;
        }
        $credentials = CommunityAccessCredential::withoutGlobalScope(TenantScope::class)->where('tenant_id', $member->tenant_id)
            ->where('member_id', $member->id)->whereIn('id', array_keys($grants))->whereNull('revoked_at')->whereNull('suspended_at')->get();
        foreach ($credentials as $credential) {
            if ($this->valid($credential, $member, $phone) && ($grants[$credential->id]['version'] ?? null) === $credential->version) {
                return true;
            }
        }

        return false;
    }

    public function establish(Session $session, string $selector, string $secret): bool
    {
        $phone = $this->phones->verifiedPhone($session);
        if ($phone === null || ! Str::isUuid($selector) || mb_strlen($secret) > 128) {
            return false;
        }
        $credential = CommunityAccessCredential::withoutGlobalScope(TenantScope::class)->find($selector);
        $member = $credential === null ? null : Member::withoutGlobalScope(TenantScope::class)->where('tenant_id', $credential->tenant_id)->find($credential->member_id);
        if ($credential === null || $member === null || ! $this->valid($credential, $member, $phone) || ! Hash::check($secret, $credential->secret_hash)) {
            return false;
        }
        $session->regenerate();
        $session->put('community_member_grants.'.$credential->id, ['version' => $credential->version]);

        return true;
    }

    /** @return array{selector:string, secret:string} */
    public function enrolPhone(Session $session, Member $member): array
    {
        $email = $this->emails->verifiedEmail($session);
        $phone = $this->phones->verifiedPhone($session);
        if ($email === null || $phone === null || $this->identity->normaliseEmail($email) !== $this->identity->normaliseEmail($member->email)
            || $phone !== $this->identity->normalisePhone($member->phone)) {
            throw new AuthorizationException('Verify the email and phone recorded for this membership.');
        }

        return $this->issue($session, $member, $phone, 'self', null, 'verified_member_email');
    }

    /** Trusted enrollment/approved-claim call only; never accepts an unreviewed public member ID.
     * @return array{selector:string, secret:string}
     */
    public function issue(Session $session, Member $member, string $phone, string $role, ?User $actor, string $method): array
    {
        abort_unless($this->phones->verifiedPhone($session) === $phone && in_array($role, ['self', 'representative'], true), 403);

        return $member->getConnection()->transaction(function () use ($session, $member, $phone, $role, $actor, $method): array {
            $locked = Member::withoutGlobalScope(TenantScope::class)->where('tenant_id', $member->tenant_id)->lockForUpdate()->findOrFail($member->id);
            if ($method === 'verified_member_email') {
                $email = $this->emails->verifiedEmail($session);
                abort_unless($email !== null && $this->identity->normaliseEmail($email) === $this->identity->normaliseEmail($locked->email), 403);
            }
            abort_if($this->phoneHeld($locked->tenant_id, PhoneVerification::digest($phone)), 403, 'This number needs an independent ownership review.');
            abort_if($role === 'self' && $phone !== $this->identity->normalisePhone($locked->phone), 403);
            CommunityAccessCredential::withoutGlobalScope(TenantScope::class)->where('tenant_id', $locked->tenant_id)->where('member_id', $locked->id)
                ->where('phone_hash', PhoneVerification::digest($phone))->where('role', $role)->whereNull('revoked_at')->update(['revoked_at' => now()]);
            $secret = bin2hex(random_bytes(32));
            $credential = CommunityAccessCredential::query()->create(['tenant_id' => $locked->tenant_id, 'member_id' => $locked->id, 'phone' => $phone,
                'phone_hash' => PhoneVerification::digest($phone), 'role' => $role, 'secret_hash' => Hash::make($secret), 'version' => 1,
                'member_access_version' => $locked->access_version, 'approved_by' => $actor?->id, 'method' => $method, 'approved_at' => now()]);
            CommunityAccessAudit::query()->create(['tenant_id' => $locked->tenant_id, 'member_id' => $locked->id, 'actor_id' => $actor?->id, 'action' => 'credential_issued', 'method' => $method]);
            $session->put('community_member_grants.'.$credential->id, ['version' => 1]);
            $session->regenerate();

            return ['selector' => $credential->id, 'secret' => $secret];
        });
    }

    public function revoke(Member $member, User $actor, string $reason): void
    {
        $this->authorizeStaff($member->tenant, $actor);
        abort_if(mb_trim($reason) === '', 422);
        $member->getConnection()->transaction(function () use ($member, $actor, $reason): void {
            $locked = Member::withoutGlobalScope(TenantScope::class)->where('tenant_id', $member->tenant_id)->lockForUpdate()->findOrFail($member->id);
            $locked->increment('access_version');
            \App\Models\CommunityAccessClaim::withoutGlobalScope(TenantScope::class)->where('tenant_id', $locked->tenant_id)->where('member_id', $locked->id)->whereIn('status', ['pending', 'approved'])->update(['status' => 'expired']);
            CommunityAccessCredential::withoutGlobalScope(TenantScope::class)->where('tenant_id', $locked->tenant_id)->where('member_id', $locked->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
            CommunityAccessAudit::query()->create(['tenant_id' => $locked->tenant_id, 'member_id' => $locked->id, 'actor_id' => $actor->id, 'action' => 'access_revoked', 'reason' => $reason]);
        });
    }

    public function revokeCredential(CommunityAccessCredential $credential, User $actor, string $reason): void
    {
        $member = Member::withoutGlobalScope(TenantScope::class)->where('tenant_id', $credential->tenant_id)->findOrFail($credential->member_id);
        $this->authorizeStaff($member->tenant, $actor);
        abort_if(mb_strlen(mb_trim($reason)) < 10 || mb_strlen($reason) > 1000, 422);
        $credential->getConnection()->transaction(function () use ($credential, $actor, $reason): void {
            $locked = CommunityAccessCredential::withoutGlobalScope(TenantScope::class)->where('tenant_id', $credential->tenant_id)->lockForUpdate()->findOrFail($credential->id);
            if ($locked->revoked_at !== null) {
                return;
            }
            $locked->update(['revoked_at' => now()]);
            CommunityAccessAudit::query()->create(['tenant_id' => $locked->tenant_id, 'member_id' => $locked->member_id, 'actor_id' => $actor->id, 'action' => 'credential_revoked', 'reason' => $reason]);
        });
    }

    public function authorizeStaff(Tenant $tenant, User $actor): void
    {
        if ($actor->tenant_id !== $tenant->id && ! $tenant->users()->where('users.id', $actor->id)->exists()) {
            throw new AuthorizationException;
        }
        $previousTeam = getPermissionsTeamId();
        try {
            setPermissionsTeamId($tenant->id);
            Gate::forUser($actor->fresh())->authorize('manage members');
        } finally {
            setPermissionsTeamId($previousTeam);
        }
    }

    public function forget(Session $session): void
    {
        $session->forget(['community_member_grants', 'community_claim_capabilities']);
    }

    private function phoneHeld(string $tenantId, string $hash): bool
    {
        return \App\Models\CommunityWrongNumberReport::withoutGlobalScope(TenantScope::class)->where('tenant_id', $tenantId)->where('phone_hash', $hash)->where('status', 'confirmed')->exists();
    }

    private function valid(CommunityAccessCredential $credential, Member $member, string $phone): bool
    {
        return ! $this->phoneHeld($member->tenant_id, $credential->phone_hash) && $credential->revoked_at === null && $credential->suspended_at === null
            && $credential->approved_at !== null && $credential->member_access_version === $member->access_version
            && hash_equals($credential->phone_hash, PhoneVerification::digest($phone))
            && ($credential->role === 'representative' || ($credential->role === 'self' && $this->identity->normalisePhone($member->phone) === $phone));
    }
}
