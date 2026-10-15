<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant\Community;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateReminderSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage members') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reminders_enabled' => ['required', 'boolean'],
            'reminder_offsets' => ['required', 'array:before,on_day,after_7,after_30'],
            'reminder_offsets.before' => ['required', 'integer', 'between:1,365'],
            'reminder_offsets.on_day' => ['required', 'integer', 'in:0'],
            'reminder_offsets.after_7' => ['required', 'integer', 'between:1,365'],
            'reminder_offsets.after_30' => ['required', 'integer', 'between:1,365'],
            'frequent_digest' => ['required', Rule::in(['daily', 'weekly'])],
            'sms_monthly_limit' => ['nullable', 'integer', 'between:0,2147483647'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'reminder_offsets.*.between' => 'Choose between 1 and 365 days.',
            'reminder_offsets.on_day.in' => 'The due-date reminder must be on the due date.',
            'frequent_digest.in' => 'Choose a daily or weekly digest.',
            'sms_monthly_limit.between' => 'Enter zero or a positive whole number of SMS credits.',
        ];
    }
}
