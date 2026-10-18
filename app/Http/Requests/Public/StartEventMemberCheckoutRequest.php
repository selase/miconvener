<?php

declare(strict_types=1);

namespace App\Http\Requests\Public;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StartEventMemberCheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['provider' => ['required', Rule::in(['paystack', 'stripe'])], 'expected_amount' => ['required', 'integer', 'min:0'], 'currency' => ['required', 'string', 'size:3']];
    }

    public function messages(): array
    {
        return ['expected_amount.required' => 'Review and accept the current payment total.', 'provider.required' => 'Choose an available payment method.', 'currency.required' => 'Confirm the currency shown for this registration.'];
    }
}
