<?php

declare(strict_types=1);

namespace App\Http\Requests\Attendee;

use Illuminate\Foundation\Http\FormRequest;

final class ConfirmPhoneAccessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return (new PhoneAccessRequest)->rules() + ['code' => ['required', 'string', 'regex:/^[0-9]{6}$/D']];
    }

    public function messages(): array
    {
        return (new PhoneAccessRequest)->messages() + ['code.regex' => 'Enter the six-digit code.', 'code.required' => 'Enter the code sent to your phone.'];
    }
}
