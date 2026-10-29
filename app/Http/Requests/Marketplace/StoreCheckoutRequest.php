<?php

declare(strict_types=1);

namespace App\Http\Requests\Marketplace;

use Illuminate\Foundation\Http\FormRequest;

final class StoreCheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['submission_key' => ['required', 'uuid'], 'accepted_quote_hash' => ['sometimes', 'nullable', 'string', 'regex:/^[a-f0-9]{64}$/D'], 'amount' => ['prohibited'], 'price' => ['prohibited'], 'fee' => ['prohibited'],
            'buyer_email' => ['prohibited'], 'tenant_id' => ['prohibited'], 'reference' => ['prohibited']];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['submission_key.uuid' => 'Refresh the checkout and try again.'];
    }
}
