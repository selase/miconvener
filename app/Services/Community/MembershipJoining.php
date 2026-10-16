<?php

declare(strict_types=1);

namespace App\Services\Community;

use App\Enum\MemberStatusEnum;
use App\Models\CommunityAccessAudit;
use App\Models\CommunityJoinRequest;
use App\Models\Member;
use App\Models\MembershipType;
use App\Models\Tenant;
use App\Models\User;
use App\Scopes\TenantScope;
use App\Services\Events\PlatformAttendeeVerification;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class MembershipJoining
{
    public function __construct(private readonly MemberCap $cap, private readonly JoinSettings $settings, private readonly MemberEnrollment $enrollment, private readonly MemberAccess $access) {}

    /** @param array<string,mixed> $input */
    public function submit(Tenant $tenant, Session $session, array $input, string $requestKey): CommunityJoinRequest
    {
        $channel = $input['channel'] ?? 'email';
        $address = $this->address($session, $channel) ?? throw new AuthorizationException('Verify this contact first.');
        $name = mb_trim((string) ($input['name'] ?? ''));
        if (! Str::isUuid($requestKey) || $name === '' || mb_strlen($name) > 255) {
            throw ValidationException::withMessages(['name' => 'Enter your name and use a valid request key.']);
        }
        $hash = app(MessagingPreferences::class)->hash($channel, $address);

        return $this->cap->withLock($tenant, function () use ($tenant, $session, $name, $channel, $address, $hash, $requestKey): CommunityJoinRequest {
            $configuration = $this->settings->publicConfiguration($tenant);
            $existing = CommunityJoinRequest::withoutGlobalScope(TenantScope::class)->where('tenant_id', $tenant->id)->where('channel', $channel)->where('address_hash', $hash)->where('request_key', $requestKey)->first();
            if ($existing !== null) {
                return $existing;
            }
            $recent = CommunityJoinRequest::withoutGlobalScope(TenantScope::class)->where('tenant_id', $tenant->id)->where('channel', $channel)->where('address_hash', $hash)->where('submitted_at', '>=', now()->subDay())->count();
            abort_if($recent >= 5, 429, 'Please try again later.');
            $identity = app(MemberIdentity::class);
            $matches = false;
            foreach (Member::withoutGlobalScope(TenantScope::class)->where('tenant_id', $tenant->id)->select(['email', 'phone'])->cursor() as $candidate) {
                $contact = $channel === 'email' ? $identity->normaliseEmail($candidate->email) : $identity->normalisePhone($candidate->phone);
                if ($contact === $address) {
                    $matches = true;
                    break;
                }
            }
            $member = null;
            if (! $matches) {
                $member = $this->enrollment->create($tenant, ['name' => $name, 'membership_type_id' => $configuration['type']->id,
                    $channel === 'email' ? 'email' : 'phone' => $address], $configuration['settings']->join_mode === 'immediate' ? MemberStatusEnum::Active : MemberStatusEnum::Pending);
            }
            $capability = bin2hex(random_bytes(32));
            $row = CommunityJoinRequest::query()->create(['tenant_id' => $tenant->id, 'channel' => $channel, 'address' => $address, 'address_hash' => $hash, 'request_key' => $requestKey,
                'submitted_name' => $name, 'membership_type_id' => $configuration['type']->id, 'member_id' => $member?->id, 'member_access_version' => $member?->access_version,
                'status' => $member === null ? 'review_required' : ($member->status === MemberStatusEnum::Active ? 'approved' : 'pending'),
                'capability_hash' => hash('sha256', $capability), 'submitted_at' => now()]);
            $session->put('community_join_capabilities.'.$row->id, $capability);

            return $row;
        });
    }

    public function approve(CommunityJoinRequest $request, User $actor, bool $createDistinct = false, ?string $reason = null): Member
    {
        $tenant = Tenant::findOrFail($request->tenant_id);
        $this->access->authorizeStaff($tenant, $actor);
        abort_unless($tenant->communityEnabled(), 404);

        return $this->cap->withLock($tenant, function () use ($tenant, $request, $actor, $createDistinct, $reason): Member {
            $row = CommunityJoinRequest::withoutGlobalScope(TenantScope::class)->where('tenant_id', $tenant->id)->lockForUpdate()->findOrFail($request->id);
            if ($row->status === 'rejected') {
                throw new DomainException('This application was rejected.');
            }
            $member = $row->member_id === null ? null : Member::withoutGlobalScope(TenantScope::class)->where('tenant_id', $tenant->id)->lockForUpdate()->findOrFail($row->member_id);
            if ($row->status === 'approved' && $member !== null) {
                return $member;
            }
            $type = MembershipType::withoutGlobalScope(TenantScope::class)->where('tenant_id', $tenant->id)->where('is_active', true)->find($row->membership_type_id);
            if ($type === null) {
                throw new DomainException('The selected membership type is no longer active.');
            }
            $limit = $this->cap->limit($tenant);
            if ($limit !== null && $this->cap->counted($tenant) > $limit) {
                throw new DomainException('The organisation is over its member limit.');
            }
            if ($member === null) {
                if (! $createDistinct || mb_strlen(mb_trim($reason ?? '')) < 10) {
                    throw ValidationException::withMessages(['create_distinct' => 'Review the contact ambiguity and explicitly confirm a separate new membership. Existing records need the independent claim process.']);
                }
                $member = $this->enrollment->create($tenant, ['name' => $row->submitted_name, 'membership_type_id' => $type->id, $row->channel === 'email' ? 'email' : 'phone' => $row->address], MemberStatusEnum::Active);
            } else {
                if ($member->status !== MemberStatusEnum::Pending) {
                    throw new DomainException('This member is no longer awaiting approval.');
                }
                $member->update(['status' => MemberStatusEnum::Active]);
            }
            $row->update(['member_id' => $member->id, 'status' => 'approved', 'reviewed_by' => $actor->id, 'review_reason' => $reason, 'reviewed_at' => now()]);
            CommunityAccessAudit::query()->create(['tenant_id' => $tenant->id, 'member_id' => $member->id, 'actor_id' => $actor->id, 'action' => 'joining_approved', 'method' => $createDistinct ? 'create_distinct' : 'staff_review', 'reason' => $reason]);

            return $member;
        });
    }

    public function reject(CommunityJoinRequest $request, User $actor, string $reason): void
    {
        $tenant = Tenant::findOrFail($request->tenant_id);
        $this->access->authorizeStaff($tenant, $actor);
        abort_unless($tenant->communityEnabled(), 404);
        if (mb_strlen(mb_trim($reason)) < 10 || mb_strlen($reason) > 1000) {
            throw ValidationException::withMessages(['reason' => 'Give a review reason of 10 to 1000 characters.']);
        }
        $this->cap->withLock($tenant, function () use ($request, $actor, $reason, $tenant): void {
            $row = CommunityJoinRequest::withoutGlobalScope(TenantScope::class)->where('tenant_id', $tenant->id)->lockForUpdate()->findOrFail($request->id);
            if ($row->status === 'rejected') {
                return;
            }
            if (! in_array($row->status, ['pending', 'review_required'], true)) {
                throw new DomainException('Only an awaiting application can be rejected.');
            }
            if ($row->member_id !== null) {
                $member = Member::withoutGlobalScope(TenantScope::class)->where('tenant_id', $tenant->id)->lockForUpdate()->findOrFail($row->member_id);
                if ($member->status !== MemberStatusEnum::Pending) {
                    throw new DomainException('The membership has already changed.');
                }
                $member->update(['status' => MemberStatusEnum::Exited]);
            }
            $row->update(['status' => 'rejected', 'reviewed_by' => $actor->id, 'review_reason' => $reason, 'reviewed_at' => now()]);
            CommunityAccessAudit::query()->create(['tenant_id' => $tenant->id, 'member_id' => $row->member_id, 'actor_id' => $actor->id, 'action' => 'joining_rejected', 'method' => 'staff_review', 'reason' => $reason]);
        });
    }

    public function owns(Session $session, CommunityJoinRequest $row): bool
    {
        $capability = $session->get('community_join_capabilities.'.$row->id);

        return is_string($capability) && hash_equals($row->capability_hash, hash('sha256', $capability))
            && $this->address($session, $row->channel) === $row->address;
    }

    /** Newly created, unambiguous memberships only; ambiguous approvals use independently reviewed claims.
     * @return array{selector:string,secret:string}
     */
    public function credential(Session $session, CommunityJoinRequest $request): array
    {
        return $this->cap->withLock(Tenant::findOrFail($request->tenant_id), function () use ($session, $request): array {
            $member = Member::withoutGlobalScope(TenantScope::class)->where('tenant_id', $request->tenant_id)->lockForUpdate()->findOrFail($request->member_id);
            $row = CommunityJoinRequest::withoutGlobalScope(TenantScope::class)->lockForUpdate()->findOrFail($request->id);
            abort_unless($this->owns($session, $row) && $row->channel === 'sms' && $row->member_access_version !== null && $row->member_access_version === $member->access_version
                && $row->credential_issued_at === null && $row->status === 'approved' && $member->tenant->communityEnabled(), 404);
            $result = $this->access->issue($session, $member, $row->address, 'self', null, 'new_membership');
            $row->update(['credential_issued_at' => now()]);

            return $result;
        });
    }

    private function address(Session $session, string $channel): ?string
    {
        return match ($channel) {
            'email' => app(PlatformAttendeeVerification::class)->verifiedEmail($session),
            'sms' => app(PhoneVerification::class)->verifiedPhone($session),
            default => throw ValidationException::withMessages(['channel' => 'Choose email or SMS.']),
        };
    }
}
