<?php

declare(strict_types=1);

namespace App\Services\Community;

use App\Models\CommunityAccessAudit;
use App\Models\CommunityAccessClaim;
use App\Models\Member;
use App\Models\Tenant;
use App\Models\User;
use App\Scopes\TenantScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Session\Session;
use Illuminate\Validation\ValidationException;

final class MemberClaims
{
    public function __construct(private readonly PhoneVerification $phones, private readonly MemberAccess $access, private readonly MemberIdentity $identity) {}

    public function request(Tenant $tenant, Session $session, string $name, string $role): CommunityAccessClaim
    {
        abort_unless($tenant->communityEnabled(), 404);
        $phone = $this->phones->verifiedPhone($session);
        if ($phone === null) {
            throw new AuthorizationException('Verify your phone before requesting access.');
        }
        if (mb_trim($name) === '' || mb_strlen($name) > 160 || ! in_array($role, ['self', 'representative'], true)) {
            throw ValidationException::withMessages(['name' => 'Enter your name and choose who you represent.']);
        }

        return (new CommunityAccessClaim)->getConnection()->transaction(function () use ($tenant, $session, $phone, $name, $role): CommunityAccessClaim {
            (new CommunityAccessClaim)->getConnection()->select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ['member-claim:'.$tenant->id.':'.PhoneVerification::digest($phone)]);
            abort_if(CommunityAccessClaim::withoutGlobalScope(TenantScope::class)->where('tenant_id', $tenant->id)->where('phone_hash', PhoneVerification::digest($phone))->where('created_at', '>', now()->subDay())->count() >= 5, 429);
            $capability = bin2hex(random_bytes(32));
            $claim = CommunityAccessClaim::query()->create(['tenant_id' => $tenant->id, 'phone' => $phone, 'phone_hash' => PhoneVerification::digest($phone), 'claimant_name' => mb_trim($name), 'role' => $role, 'capability_hash' => hash('sha256', $capability), 'status' => 'pending', 'expires_at' => now()->addDays(7)]);
            $session->put('community_claim_capabilities.'.$claim->id, $capability);

            return $claim;
        });
    }

    public function approve(CommunityAccessClaim $claim, Member $member, User $actor, string $method, string $reason): void
    {
        abort_unless($claim->tenant_id === $member->tenant_id, 404);
        $this->access->authorizeStaff($claim->tenant, $actor);
        if (! in_array($method, ['in_person', 'known_contact'], true) || mb_strlen(mb_trim($reason)) < 10 || mb_strlen($reason) > 1000) {
            throw ValidationException::withMessages(['reason' => 'Record the independent check and its evidence (10–1000 characters).']);
        }
        $claim->getConnection()->transaction(function () use ($claim, $member, $actor, $method, $reason): void {
            $person = Member::withoutGlobalScope(TenantScope::class)->where('tenant_id', $claim->tenant_id)->lockForUpdate()->findOrFail($member->id);
            $locked = CommunityAccessClaim::withoutGlobalScope(TenantScope::class)->where('tenant_id', $claim->tenant_id)->lockForUpdate()->findOrFail($claim->id);
            abort_unless($locked->status === 'pending' && $locked->expires_at->isFuture(), 410);
            abort_if($locked->role === 'self' && $this->identity->normalisePhone($person->phone) !== $locked->phone, 422, 'The member contact must match the independently verified number.');
            $locked->update(['member_id' => $person->id, 'member_access_version' => $person->access_version, 'status' => 'approved', 'reviewed_by' => $actor->id, 'method' => $method, 'reason' => $reason]);
            CommunityAccessAudit::query()->create(['tenant_id' => $locked->tenant_id, 'member_id' => $person->id, 'actor_id' => $actor->id, 'action' => 'claim_approved', 'method' => $method, 'reason' => $reason]);
        });
    }

    /** @return array{selector:string, secret:string} */
    public function complete(Session $session, string $claimId, string $capability): array
    {
        $candidate = CommunityAccessClaim::withoutGlobalScope(TenantScope::class)->findOrFail($claimId);
        abort_unless($this->owns($session, $candidate, $capability), 404);
        abort_unless($candidate->status === 'approved' && $candidate->member_id !== null && $candidate->expires_at->isFuture(), 410);

        return $candidate->getConnection()->transaction(function () use ($session, $claimId, $capability, $candidate): array {
            $member = Member::withoutGlobalScope(TenantScope::class)->where('tenant_id', $candidate->tenant_id)->lockForUpdate()->findOrFail($candidate->member_id);
            $claim = CommunityAccessClaim::withoutGlobalScope(TenantScope::class)->where('tenant_id', $candidate->tenant_id)->lockForUpdate()->findOrFail($claimId);
            abort_unless($this->owns($session, $claim, $capability), 404);
            abort_unless($claim->status === 'approved' && $claim->completed_at === null && $claim->expires_at->isFuture(), 410);
            abort_unless($claim->tenant->communityEnabled(), 404);
            abort_unless($claim->member_id === $member->id && $member->access_version === $claim->member_access_version, 410);
            $credential = $this->access->issue($session, $member, $claim->phone, $claim->role, User::query()->find($claim->reviewed_by), $claim->method);
            $claim->update(['status' => 'completed', 'completed_at' => now()]);

            return $credential;
        });
    }

    public function reject(CommunityAccessClaim $claim, User $actor, string $reason): void
    {
        $this->access->authorizeStaff($claim->tenant, $actor);
        abort_if(mb_trim($reason) === '' || mb_strlen($reason) > 1000, 422);
        $claim->getConnection()->transaction(function () use ($claim, $actor, $reason): void {
            $locked = CommunityAccessClaim::withoutGlobalScope(TenantScope::class)->where('tenant_id', $claim->tenant_id)->lockForUpdate()->findOrFail($claim->id);
            abort_unless($locked->status === 'pending', 410);
            $locked->update(['status' => 'rejected', 'reviewed_by' => $actor->id, 'reason' => $reason]);
            CommunityAccessAudit::query()->create(['tenant_id' => $locked->tenant_id, 'actor_id' => $actor->id, 'action' => 'claim_rejected', 'reason' => $reason]);
        });
    }

    public function owns(Session $session, CommunityAccessClaim $claim, ?string $capability = null): bool
    {
        $capability ??= $session->get('community_claim_capabilities.'.$claim->id);

        return is_string($capability) && mb_strlen($capability) === 64
            && hash_equals($claim->capability_hash, hash('sha256', $capability))
            && $this->phones->verifiedPhone($session) === $claim->phone;
    }
}
