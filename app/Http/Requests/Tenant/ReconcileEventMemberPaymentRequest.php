<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant;

use Illuminate\Foundation\Http\FormRequest;

final class ReconcileEventMemberPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update event') ?? false;
    }

    public function rules(): array
    {
        return ['provider_refund_id' => ['nullable', 'string', 'max:255']];
    }

    public function messages(): array
    {
        return ['provider_refund_id.max' => 'Use the original provider refund identifier, up to 255 characters.'];
    }
}
