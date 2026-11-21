<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

final class ConfirmInvitationMfaRequest extends OrganisationInvitationTokenRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return $this->tokenRules() + ['code' => ['required', 'digits:6']];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['code.digits' => 'Enter the six-digit code from your authenticator app.'];
    }
}
