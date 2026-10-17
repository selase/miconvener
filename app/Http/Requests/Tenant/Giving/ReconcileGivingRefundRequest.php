<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant\Giving;

use Illuminate\Foundation\Http\FormRequest;

final class ReconcileGivingRefundRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('read finance') && $this->user()->can('process refunds');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['provider_refund_reference' => ['nullable', 'string', 'max:190', 'regex:/\A[A-Za-z0-9_-]+\z/']];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['provider_refund_reference.regex' => 'Enter the refund ID shown by the original payment provider.'];
    }
}
