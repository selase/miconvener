<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

final class RequestAccountEmailChangeRequest extends AccountEmailChangeRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:1024'],
            'code' => ['nullable', 'digits:6'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $email = $this->input('email');
        if (is_string($email)) {
            $this->merge(['email' => mb_strtolower(mb_trim($email))]);
        }
    }
}
