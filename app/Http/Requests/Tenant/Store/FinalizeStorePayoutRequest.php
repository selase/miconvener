<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant\Store;

use Illuminate\Foundation\Http\FormRequest;

final class FinalizeStorePayoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('manage store finance');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['otp' => ['required', 'string', 'regex:/^[0-9]{4,12}$/D']];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['otp.regex' => 'Enter the one-time code supplied for this payout.'];
    }
}
