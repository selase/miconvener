<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

final class SetupInvitationMfaRequest extends OrganisationInvitationTokenRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return $this->tokenRules();
    }
}
