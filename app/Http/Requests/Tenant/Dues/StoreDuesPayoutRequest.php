<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant\Dues;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A request to pay collected dues out to one of the organisation's own payout
 * accounts. Whether the balance covers it is checked under a lock by the
 * controller, not here.
 */
final class StoreDuesPayoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('manage payouts');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->getTenant()?->id;

        return [
            'payout_account_id' => ['required', Rule::exists('tenant_payout_accounts', 'id')->where('tenant_id', $tenantId)],
            'amount' => ['required', 'integer', 'min:1'],
            'currency' => ['required', 'string', 'size:3'],
            'scheduled_at' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'payout_account_id.required' => 'Choose the account to pay the dues into.',
            'payout_account_id.exists' => 'That payout account does not belong to this organisation.',
            'amount.required' => 'Enter the amount to pay out.',
            'amount.integer' => 'The amount must be a whole number of minor units.',
            'amount.min' => 'The amount must be greater than zero.',
            'currency.required' => 'Choose the currency to pay out.',
            'currency.size' => 'The currency must be a three-letter code, such as GHS.',
        ];
    }

    /**
     * Ledger accounts and payouts store currency codes in upper case.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('currency'))) {
            $this->merge(['currency' => mb_strtoupper($this->input('currency'))]);
        }
    }
}
