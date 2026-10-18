<?php

declare(strict_types=1);

namespace App\Services\Events;

use App\Enum\MemberStatusEnum;
use App\Enum\TenantStatusEnum;
use App\Models\Event;
use App\Models\Member;
use App\Models\Tenant;
use App\Scopes\TenantScope;
use App\Services\Community\MemberAccess;
use App\Services\Community\MemberIdentity;
use Illuminate\Contracts\Session\Session;

final class MemberBenefitEligibility
{
    public function __construct(private readonly MemberAccess $access, private readonly MemberIdentity $identity) {}

    public function enabled(Tenant $tenant): bool
    {
        return Tenant::query()->whereKey($tenant->id)->where('status', TenantStatusEnum::ACTIVE)
            ->whereHas('features', fn ($query) => $query->where('feature_key', 'community')->where('enabled', true))
            ->whereHas('features', fn ($query) => $query->where('feature_key', 'community_event_benefits')->where('enabled', true))->exists();
    }

    public function resolve(Session $session, Event $event, string $registrationEmail): ?Member
    {
        $tenant = Tenant::query()->find($event->tenant_id);
        if ($tenant === null || ! $this->enabled($tenant)) {
            return null;
        }

        return $this->resolveExisting($session, $event, $registrationEmail);
    }

    /** Existing paid registrations retain self-service recovery after rollout is disabled. */
    public function resolveExisting(Session $session, Event $event, string $registrationEmail): ?Member
    {
        $email = $this->identity->normaliseEmail($registrationEmail);
        $tenant = Tenant::query()->where('status', TenantStatusEnum::ACTIVE)->find($event->tenant_id);
        if ($email === null || $tenant === null) {
            return null;
        }
        foreach (Member::withoutGlobalScope(TenantScope::class)->where('tenant_id', $tenant->id)
            ->where('status', MemberStatusEnum::Active)->whereRaw('LOWER(email) = ?', [$email])->orderBy('id')->cursor() as $member) {
            if ($this->access->role($session, $member) === 'self') {
                return $member;
            }
        }

        return null;
    }
}
