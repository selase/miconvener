<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant\Dues;

/**
 * A new member.
 */
final class StoreMemberRequest extends MemberRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->memberRules(null);
    }
}
