<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant\Store;

final class AdjustMerchandiseStockRequest extends CatalogRequest
{
    /** @return array<string,mixed> */
    public function rules(): array
    {
        return ['delta' => ['required', 'integer', 'not_in:0', 'between:-2147483647,2147483647'],
            'reason' => ['required', 'string', 'max:500'], 'idempotency_key' => ['required', 'uuid']];
    }

    /** @return array<string,string> */
    public function messages(): array
    {
        return ['delta.not_in' => 'Enter a non-zero stock correction.', 'reason.required' => 'Explain why the stock is changing.'];
    }
}
