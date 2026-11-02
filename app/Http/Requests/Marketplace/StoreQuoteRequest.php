<?php

declare(strict_types=1);

namespace App\Http\Requests\Marketplace;

use Illuminate\Foundation\Http\FormRequest;

final class StoreQuoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['lines' => ['required', 'array', 'min:1', 'max:20'], 'lines.*' => ['required', 'array:variant_id,quantity'],
            'lines.*.variant_id' => ['required', 'uuid', 'distinct'], 'lines.*.quantity' => ['required', 'integer', 'between:1,99'],
            'submission_key' => ['required', 'uuid'], 'amount' => ['prohibited'], 'price' => ['prohibited'], 'fee' => ['prohibited'],
            'buyer_email' => ['prohibited'], 'tenant_id' => ['prohibited'], 'reference' => ['prohibited']];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['lines.max' => 'Choose at most 20 options per order.', 'lines.*.quantity.between' => 'Choose between 1 and 99 of each option.'];
    }
}
