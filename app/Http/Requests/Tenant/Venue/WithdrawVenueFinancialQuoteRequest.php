<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant\Venue;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A host's withdrawal of an open final quote. The reason is shown to the buyer.
 */
final class WithdrawVenueFinancialQuoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('manage venue');
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
            'reason.required' => 'Tell the buyer why the quote is withdrawn.',
        ];
    }
}
