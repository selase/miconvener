<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant\Dues;

use App\Models\LedgerAccount;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A request to pay collected dues out to one of the organisation's own payout
 * accounts, in a currency it has collected dues in: the app keeps no list of
 * supported currencies, and a dues payable account exists exactly for the
 * currencies dues were recorded in, so an unknown code cannot create one. Whether the balance covers it is checked under a lock by the
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

        $category = $this->input('category', 'dues');

        return [
            'category' => ['sometimes', Rule::in(['dues', 'giving'])],
            'payout_account_id' => ['required', Rule::exists('tenant_payout_accounts', 'id')->where('tenant_id', $tenantId)],
            'amount' => ['required', 'integer', 'min:1'],
            'currency' => [
                'required',
                'string',
                'size:3',
                Rule::exists('ledger_accounts', 'currency')
                    ->where('tenant_id', $tenantId)
                    ->whereNull('event_id')
                    ->where('category', $category)
                    ->where('code', LedgerAccount::CODE_ORGANIZER_PAYABLE),
            ],
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
            'currency.exists' => $this->input('category', 'dues') === 'giving' ? 'No giving has been collected in that currency.' : 'No dues have been collected in that currency.',
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
