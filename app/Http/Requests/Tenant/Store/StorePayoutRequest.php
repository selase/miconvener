<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant\Store;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StorePayoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('manage store finance');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['operation_key' => ['required', 'uuid'], 'amount' => ['required', 'integer', 'min:1', 'max:9007199254740991'],
            'currency' => ['required', Rule::in(['GHS'])], 'payout_account_id' => ['required', 'uuid'], 'note' => ['nullable', 'string', 'max:500'],
            'reference' => ['prohibited'], 'status' => ['prohibited'], 'tenant_id' => ['prohibited'], 'fee' => ['prohibited']];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['amount.required' => 'Enter a payout amount.', 'currency.in' => 'Commerce payouts currently support GHS only.'];
    }
}
