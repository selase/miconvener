<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant\Venue;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A full refund of one captured venue payment.
 */
final class RefundVenueChargeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('manage venue refunds');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'operation_key' => ['required', 'uuid'],
            'reason' => ['required', 'string', 'min:1', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'Give a reason for this refund.',
        ];
    }
}
