<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant\Dues;

/**
 * A new dues class for the organisation.
 */
final class StoreMembershipTypeRequest extends MembershipTypeRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->classRules(null);
    }
}
