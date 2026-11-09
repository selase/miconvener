<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant\Finance;

use Illuminate\Foundation\Http\FormRequest;

final class StorePayoutScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('manage payout schedules');
    }

    /** @return array<string,list<string>> */
    public function rules(): array
    {
        return ['category' => ['required', 'string'], 'currency' => ['required', 'string'], 'payout_account_id' => ['required', 'uuid'],
            'timezone' => ['required', 'timezone'], 'cadence' => ['required', 'string'], 'weekday' => ['nullable', 'integer'], 'local_time' => ['required', 'date_format:H:i'],
            'minimum_amount' => ['required', 'integer'], 'maximum_amount' => ['required', 'integer'], 'daily_limit' => ['required', 'integer'],
            'rolling_limit' => ['required', 'integer'], 'reserve_amount' => ['required', 'integer'],
            'tenant_id' => ['prohibited'], 'status' => ['prohibited'], 'approved_by' => ['prohibited'], 'auto_payout_enabled' => ['prohibited']];
    }

    public function messages(): array
    {
        return ['payout_account_id.required' => 'Choose a verified payout destination.', 'reserve_amount.required' => 'Specify the reserve, including an explicit zero if agreed.'];
    }
}
