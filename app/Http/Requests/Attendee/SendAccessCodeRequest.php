<?php

declare(strict_types=1);

namespace App\Http\Requests\Attendee;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Asking for a code: from a portal page by registration, or from /my by address.
 */
final class SendAccessCodeRequest extends FormRequest
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
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['email.email' => 'Enter the address you registered with.'];
    }
}
