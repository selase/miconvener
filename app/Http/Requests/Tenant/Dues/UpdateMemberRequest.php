<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant\Dues;

/**
 * Edits a member. Their own phone and email do not count as duplicates.
 */
final class UpdateMemberRequest extends MemberRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->memberRules((string) $this->route('member'));
    }
}
