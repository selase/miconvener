<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant;

use Illuminate\Foundation\Http\FormRequest;

final class StoreEventStaffLinkRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:80'],
            'pin' => ['nullable', 'string', 'regex:/^\d{4,8}$/'],
            'can_check_in' => ['required', 'boolean'],
            'can_handle_requests' => ['required', 'boolean', 'accepted_if:can_check_in,false'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Name the link after who will hold it, for example "Gate A – Kofi".',
            'pin.regex' => 'A PIN is 4 to 8 digits.',
            'can_handle_requests.accepted_if' => 'A staff link must allow scanning, requests, or both.',
        ];
    }
}
