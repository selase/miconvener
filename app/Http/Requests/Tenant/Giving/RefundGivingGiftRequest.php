<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant\Giving;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class RefundGivingGiftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('read finance') && $this->user()->can('process refunds');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $store = $this->routeIs('tenant.community.giving.refunds.store');

        return ['principal' => ['required', 'integer', 'min:1', 'max:999999999999'],
            'reason' => [Rule::requiredIf($store), 'nullable', 'string', 'max:1000'],
            'operation_key' => [Rule::requiredIf($store), 'nullable', 'uuid']];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['reason.required' => 'Provide a reason for this refund.', 'principal.required' => 'Enter the amount to refund.'];
    }
}
