<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant;

use Illuminate\Foundation\Http\FormRequest;

/**
 * An organiser correcting who a registration belongs to. Permission is checked
 * in the controller, alongside approve, reject and cancel.
 */
final class UpdateEventRegistrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'full_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'full_name.required' => 'Enter the attendee\'s name.',
            'email.required' => 'Enter the attendee\'s email address.',
            'email.email' => 'That doesn\'t look like an email address.',
        ];
    }
}
