<?php

declare(strict_types=1);

namespace App\Http\Requests\Public;

use Illuminate\Foundation\Http\FormRequest;

final class EventMemberEligibilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['email' => ['required', 'email', 'max:255']];
    }

    public function messages(): array
    {
        return ['email.required' => 'Enter the email used for your membership.', 'email.email' => 'Enter a valid email address.'];
    }
}
