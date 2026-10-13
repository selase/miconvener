<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant\Dues;

use App\Models\Member;
use App\Services\Tenancy\TenantContext;

/**
 * Edits a member. Their own phone and email do not count as duplicates, and their current
 * class is accepted even after it has been deactivated.
 */
final class UpdateMemberRequest extends MemberRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $memberId = (string) $this->route('member');
        $tenantId = app(TenantContext::class)->getTenant()?->id;
        $currentClassId = Member::query()->where('tenant_id', $tenantId)->whereKey($memberId)->value('dues_class_id');

        return $this->memberRules($memberId, $currentClassId);
    }
}
