<?php

declare(strict_types=1);

namespace App\Services\Community;

use App\Http\Resources\Community\MyCommunityResource;
use App\Models\CommunityAccessCredential;
use App\Models\CommunityFollower;
use App\Models\Member;
use App\Models\Tenant;
use App\Scopes\TenantScope;
use App\Services\Events\PlatformAttendeeVerification;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\Eloquent\Builder;

final class CommunityHistory
{
    public function __construct(private readonly MemberAccess $access, private readonly PlatformAttendeeVerification $emails, private readonly PhoneVerification $phones, private readonly MessagingPreferences $preferences) {}

    /** @return array<string,mixed> */
    public function communities(Session $session): array
    {
        $members = $this->memberQuery($session);
        $followers = $this->followerQuery($session);
        $rows = Tenant::query()->where(function ($query) use ($members, $followers): void {
            $query->whereIn('id', $members->select('tenant_id'))->orWhereIn('id', $followers->select('tenant_id'));
        })->orderBy('name')->orderBy('id')->paginate(20);

        return $rows->through(fn (Tenant $tenant): array => (new MyCommunityResource($tenant, $session))->resolve(request()))->toArray();
    }

    /** @return LengthAwarePaginator<int,Member> */
    public function members(Tenant $tenant, Session $session): LengthAwarePaginator
    {
        return $this->memberQuery($session)->where('tenant_id', $tenant->id)->with('membershipType')
            ->withSum(['charges as open_total' => fn ($query) => $query->open()], 'amount')->orderBy('name')->orderBy('id')->paginate(20);
    }

    public function hasOrganisation(Tenant $tenant, Session $session): bool
    {
        return $this->memberQuery($session)->where('tenant_id', $tenant->id)->exists() || $this->followerQuery($session)->where('tenant_id', $tenant->id)->exists();
    }

    public function member(Tenant $tenant, Session $session, string $id): Member
    {
        $member = Member::withoutGlobalScope(TenantScope::class)->where('tenant_id', $tenant->id)->with(['tenant', 'membershipType'])->withSum(['charges as open_total' => fn ($query) => $query->open()], 'amount')->findOrFail($id);
        abort_unless($this->access->canView($session, $member), 404);

        return $member;
    }

    /** Only exact email matches or independently valid member credentials enter the SQL query.
     * @return Builder<Member>
     */
    private function memberQuery(Session $session): Builder
    {
        $email = $this->emails->verifiedEmail($session);
        $phone = $this->phones->verifiedPhone($session);
        $grants = $session->get('community_member_grants', []);
        $ids = [];
        if ($phone !== null && is_array($grants) && $grants !== []) {
            foreach (CommunityAccessCredential::withoutGlobalScope(TenantScope::class)->whereIn('id', array_keys($grants))->whereNull('revoked_at')->whereNull('suspended_at')->where('phone_hash', PhoneVerification::digest($phone))->cursor() as $credential) {
                if (($grants[$credential->id]['version'] ?? null) !== $credential->version) {
                    continue;
                }
                $member = Member::withoutGlobalScope(TenantScope::class)->where('tenant_id', $credential->tenant_id)->find($credential->member_id);
                if ($member !== null && $this->access->canView($session, $member)) {
                    $ids[] = $member->id;
                }
            }
        }

        return Member::withoutGlobalScope(TenantScope::class)->where(function ($query) use ($email, $ids): void {
            $query->whereIn('id', array_values(array_unique($ids)));
            if ($email !== null) {
                $query->orWhereRaw('lower(btrim(email)) = ?', [$email]);
            }
        });
    }

    /** @return Builder<CommunityFollower> */
    private function followerQuery(Session $session): Builder
    {
        $email = $this->emails->verifiedEmail($session);
        $phone = $this->phones->verifiedPhone($session);

        return CommunityFollower::withoutGlobalScope(TenantScope::class)->whereNull('unfollowed_at')->where(function ($query) use ($email, $phone): void {
            $query->whereRaw('1=0');
            foreach (['email' => $email, 'sms' => $phone] as $channel => $address) {
                if ($address !== null) {
                    $query->orWhere(fn ($match) => $match->where('channel', $channel)->where('address_hash', $this->preferences->hash($channel, $address)));
                }
            }
        });
    }
}
