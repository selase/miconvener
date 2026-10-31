<?php

declare(strict_types=1);

namespace App\Http\Requests\Public;

use Illuminate\Foundation\Http\FormRequest;

final class ReverifyEventMemberBenefitsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['expected_amount' => ['nullable', 'required_with:currency', 'integer', 'min:0'], 'currency' => ['nullable', 'required_with:expected_amount', 'string', 'size:3']];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['expected_amount.required_with' => 'Accept the current total before continuing.', 'expected_amount.min' => 'The accepted total cannot be negative.', 'currency.required_with' => 'Confirm the currency shown with the accepted total.'];
    }
}
