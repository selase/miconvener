<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant;

use Illuminate\Foundation\Http\FormRequest;

final class BadgeTemplatePayerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['email' => ['required', 'email', 'max:254'], 'code' => ['nullable', 'string', 'regex:/^[0-9]{6}$/'], 'turnstile_token' => ['nullable', 'string', 'max:2048']];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['email.email' => 'Enter the payer email address.', 'code.regex' => 'Enter the six-digit email code.'];
    }
}
