<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant;

use Illuminate\Foundation\Http\FormRequest;

/**
 * One organisation's ticket commission deal: a rate and an optional cap per
 * ticket, in cedis. Blank removes that part of the deal, so the plan applies.
 */
final class UpdateTicketTermsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('manage-commercial-terms');
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'rate' => ['nullable', 'numeric', 'min:0', 'max:100', 'decimal:0,2'],
            'cap' => ['nullable', 'numeric', 'min:0.01', 'decimal:0,2'],
            'reason' => ['required', 'string', 'max:2000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'cap.min' => 'A cap must be more than zero. To waive commission, set the rate to 0.',
            'reason.required' => 'Say why these terms are being set, for the record.',
        ];
    }
}
