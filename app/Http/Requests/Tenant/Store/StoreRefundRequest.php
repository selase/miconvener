<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant\Store;

use Illuminate\Foundation\Http\FormRequest;

final class StoreRefundRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('manage store finance');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['operation_key' => ['required', 'uuid'], 'reason' => ['required', 'string', 'max:500'], 'observation_id' => ['nullable', 'uuid'],
            'amount' => ['prohibited'], 'currency' => ['prohibited'], 'reference' => ['prohibited'], 'status' => ['prohibited']];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['amount.prohibited' => 'This workflow refunds the full verified payment. Partial refunds require support review.', 'reason.required' => 'Explain why the uncollected order is being refunded.'];
    }
}
