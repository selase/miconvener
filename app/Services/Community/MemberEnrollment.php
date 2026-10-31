<?php

declare(strict_types=1);

namespace App\Services\Community;

use App\Enum\MemberStatusEnum;
use App\Models\Member;
use App\Models\Tenant;

final class MemberEnrollment
{
    public function __construct(private readonly MemberCap $cap) {}

    /** Caller resolves contact ambiguity before invoking this shared, cap-locked create action.
     * @param  array<string,mixed>  $validated
     */
    public function create(Tenant $tenant, array $validated, MemberStatusEnum $status): Member
    {
        return $this->cap->withLock($tenant, function () use ($tenant, $validated, $status): Member {
            $this->cap->assertRoomFor($tenant, 1);

            return Member::query()->create([...$validated, 'tenant_id' => $tenant->id, 'status' => $status]);
        });
    }
}
