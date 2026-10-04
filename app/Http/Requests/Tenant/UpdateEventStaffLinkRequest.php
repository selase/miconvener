<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateEventStaffLinkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update event') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:80'],
            'pin' => ['nullable', 'string', 'regex:/^\d{4,8}$/'],
            'remove_pin' => ['sometimes', 'boolean'],
            'can_check_in' => ['sometimes', 'required', 'boolean'],
            'can_handle_requests' => ['sometimes', 'required', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'pin.regex' => 'A PIN is 4 to 8 digits.',
        ];
    }
}
