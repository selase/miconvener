<?php

declare(strict_types=1);

namespace App\Http\Requests\Public\Giving;

use Illuminate\Foundation\Http\FormRequest;

final class UnsubscribeGivingReminderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        return ['token' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D']];
    }

    /** @return array<string,string> */
    public function messages(): array
    {
        return ['token.regex' => 'Use the complete unsubscribe link from your reminder.'];
    }
}
