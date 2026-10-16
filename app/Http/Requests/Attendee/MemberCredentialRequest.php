<?php

declare(strict_types=1);

namespace App\Http\Requests\Attendee;

use Illuminate\Foundation\Http\FormRequest;

final class MemberCredentialRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['selector' => ['required', 'uuid'], 'secret' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D']];
    }

    public function messages(): array
    {
        return ['selector.required' => 'Enter your membership access reference.', 'secret.regex' => 'Enter the full private recovery key.'];
    }
}
