<?php

declare(strict_types=1);

namespace App\Http\Requests\Public\Giving;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SubscribeGivingReminderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        return ['consent' => ['required', 'accepted'], 'cadence' => ['required', Rule::in(['weekly', 'monthly'])], 'anchor_day' => ['nullable', 'integer', 'between:1,31']];
    }

    /** @return array<string,string> */
    public function messages(): array
    {
        return ['consent.accepted' => 'Please explicitly agree to email giving reminders.', 'cadence.in' => 'Choose weekly or monthly reminders.'];
    }
}
