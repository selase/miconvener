<?php

declare(strict_types=1);

namespace App\Http\Requests\Public\Giving;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StartGivingCheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['amount' => ['required', 'string', 'regex:/\A[0-9]{1,10}(?:\.[0-9]{1,2})?\z/'], 'currency' => ['required', 'string', 'size:3'],
            'provider' => ['required', Rule::in(['paystack', 'stripe'])], 'submission_key' => ['required', 'uuid'],
            'payer_name' => ['nullable', 'string', 'max:160'], 'payer_email' => ['nullable', 'email', 'max:254'],
            'payer_phone' => ['nullable', 'string', 'max:40'], 'is_anonymous' => ['sometimes', 'boolean']];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['amount.regex' => 'Enter an amount with at most two decimal places.', 'payer_email.email' => 'Enter a valid email address for your receipt.'];
    }
}
