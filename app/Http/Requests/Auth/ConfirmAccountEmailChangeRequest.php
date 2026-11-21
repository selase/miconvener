<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

final class ConfirmAccountEmailChangeRequest extends AccountEmailChangeRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'password' => ['required', 'string', 'max:1024'],
            'code' => ['nullable', 'digits:6'],
            'token' => ['required', 'string', 'size:64'],
        ];
    }
}
