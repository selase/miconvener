<?php

declare(strict_types=1);

namespace App\Http\Requests\Attendee;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Entering a code. Identifies the address the same way the request for it did.
 */
final class ConfirmAccessCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'registration' => ['required_without:email', 'nullable', 'uuid'],
            'email' => ['required_without:registration', 'nullable', 'email', 'max:255'],
            'code' => ['required', 'string', 'max:12'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['code.required' => 'Enter the code from the email.'];
    }
}
