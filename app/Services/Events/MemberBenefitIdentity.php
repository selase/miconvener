<?php

declare(strict_types=1);

namespace App\Services\Events;

use App\Models\CommunityAccessCredential;
use App\Models\CommunityWrongNumberReport;
use App\Models\Member;
use App\Services\Community\MemberAccess;
use App\Services\Community\MemberIdentity;
use App\Services\Community\PhoneVerification;
use Illuminate\Contracts\Session\Session;
use Illuminate\Validation\ValidationException;

final class MemberBenefitIdentity
{
    public function __construct(private readonly MemberAccess $access, private readonly MemberIdentity $identity) {}

    /** @return array<string, mixed> */
    public function capture(Session $session, Member $member): array
    {
        if ($this->access->role($session, $member) !== 'self') {
            throw ValidationException::withMessages(['membership' => 'Verify your own membership before continuing.']);
        }
        $email = app(PlatformAttendeeVerification::class)->verifiedEmail($session);
        $proof = ['member_id' => $member->id, 'tenant_id' => $member->tenant_id, 'access_version' => $member->access_version,
            'email_digest' => $this->digest($member->email)];
        if ($email !== null && $this->identity->normaliseEmail($email) === $this->identity->normaliseEmail($member->email)) {
            return [...$proof, 'kind' => 'email', 'expires_at' => $session->get(PlatformAttendeeVerification::SESSION_KEY)['expires_at']];
        }
        $phone = app(PhoneVerification::class)->verifiedPhone($session);
        $grants = (array) $session->get('community_member_grants', []);
        $credential = CommunityAccessCredential::withoutGlobalScopes()->where('tenant_id', $member->tenant_id)->where('member_id', $member->id)
            ->where('role', 'self')->whereIn('id', array_keys($grants))->whereNull('revoked_at')->whereNull('suspended_at')
            ->where('member_access_version', $member->access_version)->where('phone_hash', PhoneVerification::digest((string) $phone))
            ->whereNotNull('approved_at')->orderBy('id')->get()->first(fn ($row) => ($grants[$row->id]['version'] ?? null) === $row->version);
        if ($credential === null) {
            throw ValidationException::withMessages(['membership' => 'Verify your own membership before continuing.']);
        }

        return [...$proof, 'kind' => 'phone', 'credential_id' => $credential->id, 'credential_version' => $credential->version,
            'phone_digest' => $credential->phone_hash, 'expires_at' => $session->get(PhoneVerification::SESSION_KEY)['expires_at']];
    }

    /** @param array<string, mixed> $proof */
    public function valid(array $proof, Member $member, bool $requireUnexpired = true): bool
    {
        if (($proof['member_id'] ?? null) !== $member->id || ($proof['tenant_id'] ?? null) !== $member->tenant_id
            || ($proof['access_version'] ?? null) !== $member->access_version || ($proof['email_digest'] ?? null) !== $this->digest($member->email)
            || ($requireUnexpired && (int) ($proof['expires_at'] ?? 0) <= now()->timestamp)) {
            return false;
        }
        if (($proof['kind'] ?? null) === 'email') {
            return true;
        }
        if (($proof['kind'] ?? null) !== 'phone') {
            return false;
        }
        $hash = $proof['phone_digest'] ?? '';

        return ! CommunityWrongNumberReport::withoutGlobalScopes()->where('tenant_id', $member->tenant_id)->where('phone_hash', $hash)->where('status', 'confirmed')->exists()
            && $hash === PhoneVerification::digest((string) $this->identity->normalisePhone($member->phone))
            && CommunityAccessCredential::withoutGlobalScopes()->whereKey($proof['credential_id'] ?? null)->where('tenant_id', $member->tenant_id)
                ->where('member_id', $member->id)->where('role', 'self')->where('phone_hash', $hash)->where('version', $proof['credential_version'] ?? 0)
                ->where('member_access_version', $member->access_version)->whereNotNull('approved_at')->whereNull('revoked_at')->whereNull('suspended_at')->exists();
    }

    private function digest(?string $email): string
    {
        return hash_hmac('sha256', (string) $this->identity->normaliseEmail($email), (string) config('app.key'));
    }
}
