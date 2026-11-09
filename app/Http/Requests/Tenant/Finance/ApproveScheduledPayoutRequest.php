<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant\Finance;

use Illuminate\Foundation\Http\FormRequest;

final class ApproveScheduledPayoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string,list<string>> */
    public function rules(): array
    {
        return ['operation_key' => [$this->routeIs('tenant.finance.schedules.approve') ? 'required' : 'nullable', 'uuid'],
            'otp' => [$this->routeIs('tenant.finance.schedules.finalize') ? 'required' : 'nullable', 'string', 'max:32'],
            'tenant_id' => ['prohibited'], 'amount' => ['prohibited'], 'payout_account_id' => ['prohibited'], 'status' => ['prohibited']];
    }

    public function messages(): array
    {
        return ['operation_key.required' => 'Refresh the proposal before approving it.', 'otp.required' => 'Enter the code Paystack sent to the account owner.'];
    }
}
