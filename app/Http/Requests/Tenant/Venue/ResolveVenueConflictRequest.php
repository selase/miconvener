<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant\Venue;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Finance retains or refunds verified money from a payment conflict.
 */
final class ResolveVenueConflictRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('reconcile venue payments');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'operation_key' => ['required', 'uuid'],
            'decision' => ['required', 'in:retain,refund'],
            'reason' => ['required', 'string', 'min:1', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'decision.in' => 'Choose retain or refund.',
            'reason.required' => 'Give a reason for this decision.',
        ];
    }
}
