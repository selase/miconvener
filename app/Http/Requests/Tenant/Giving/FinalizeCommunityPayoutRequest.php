<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant\Giving;

use Illuminate\Foundation\Http\FormRequest;

final class FinalizeCommunityPayoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('manage payouts');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['otp' => ['required', 'string', 'max:32']];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['otp.required' => 'Enter the code to release this payout.'];
    }
}
