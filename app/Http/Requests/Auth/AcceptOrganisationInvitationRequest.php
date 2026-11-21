<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Validation\Rules\Password;

final class AcceptOrganisationInvitationRequest extends OrganisationInvitationTokenRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string|Password>> */
    public function rules(): array
    {
        $rules = $this->tokenRules() + ['intent' => ['nullable', 'in:accept,signin,switch-account']];
        if ($this->user() || in_array($this->input('intent'), ['signin', 'switch-account'], true)) {
            return $rules;
        }

        return $rules + [
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['password.confirmed' => 'Enter the same password in both fields.'];
    }
}
