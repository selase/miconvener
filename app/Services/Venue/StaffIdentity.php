<?php

declare(strict_types=1);

namespace App\Services\Venue;

use App\Enum\MemberStatusEnum;
use App\Models\Member;
use App\Models\MembershipType;
use App\Models\Tenant;
use App\Services\Community\CommunityHistory;
use App\Services\Community\MemberEnrollment;
use App\Services\Events\PlatformAttendeeVerification;
use App\Services\Tenancy\FeatureService;
use Illuminate\Contracts\Session\Session;

/**
 * Who counts as an organisation's staff for room booking: a member it tagged
 * Staff, or someone who verified an address at a domain it has proved. The
 * person's verified identifier decides, for this organisation only, and only
 * while the organisation has the staff_room_booking feature (Enterprise).
 */
final class StaffIdentity
{
    public const string STAFF_TYPE_NAME = 'Staff';

    public function __construct(
        private readonly CommunityHistory $history,
        private readonly PlatformAttendeeVerification $emails,
        private readonly StaffDomains $domains,
        private readonly MemberEnrollment $enrollment,
    ) {}

    public function isStaff(Tenant $tenant, Session $session): bool
    {
        return $this->taggedMember($tenant, $session) !== null || $this->domainEmail($tenant, $session) !== null;
    }

    /**
     * The staff member's record, created (tagged Staff, with no dues) the first
     * time someone is staff only because of their verified domain.
     */
    public function memberFor(Tenant $tenant, Session $session): ?Member
    {
        $tagged = $this->taggedMember($tenant, $session);

        if ($tagged !== null) {
            return $tagged;
        }

        $email = $this->domainEmail($tenant, $session);

        if ($email === null) {
            return null;
        }

        return $this->enrollment->create($tenant, [
            'name' => $email,
            'email' => $email,
            'is_staff' => true,
            'membership_type_id' => $this->staffType($tenant)->id,
            'joined_on' => now()->toDateString(),
        ], MemberStatusEnum::Active);
    }

    private function taggedMember(Tenant $tenant, Session $session): ?Member
    {
        if (! $tenant->featureEnabled(FeatureService::FEATURE_STAFF_ROOM_BOOKING)) {
            return null;
        }

        return $this->history->memberQuery($session)
            ->where('tenant_id', $tenant->id)
            ->where('is_staff', true)
            ->where('status', '!=', MemberStatusEnum::Exited->value)
            ->orderBy('id')
            ->first();
    }

    private function domainEmail(Tenant $tenant, Session $session): ?string
    {
        if (! $tenant->featureEnabled(FeatureService::FEATURE_STAFF_ROOM_BOOKING)) {
            return null;
        }

        $email = $this->emails->verifiedEmail($session);

        return $email !== null && $this->domains->matches($tenant, $email) ? mb_strtolower($email) : null;
    }

    /** A membership type with no dues, so staff membership never generates a charge. */
    private function staffType(Tenant $tenant): MembershipType
    {
        return MembershipType::query()->firstOrCreate(
            ['tenant_id' => $tenant->id, 'name' => self::STAFF_TYPE_NAME],
            ['amount' => null, 'currency' => 'GHS', 'period' => null, 'is_active' => true],
        );
    }
}
