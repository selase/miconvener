<?php

declare(strict_types=1);

namespace App\Http\Requests\Attendee;

use Illuminate\Foundation\Http\FormRequest;

final class PhoneAccessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        return ['organiser' => ['required', 'string', 'max:100'], 'phone' => ['required', 'string', 'max:32'], 'turnstile_token' => ['nullable', 'string', 'max:2048']];
    }

    public function messages(): array
    {
        return ['phone.required' => 'Enter your phone number.', 'organiser.required' => 'Choose an organisation.'];
    }
}
