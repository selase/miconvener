<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant\Venue;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The host's final financial quote for an approved booking. Amounts are whole
 * pesewas; the host chooses full payment or an explicit deposit, and the rental
 * balance and refundable security each need their own date before the event.
 */
final class IssueVenueFinancialQuoteRequest extends FormRequest
{
    private const MAX = 9007199254740991;

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
            'rental_pesewas' => ['required', 'integer', 'min:0', 'max:'.self::MAX],
            'extras_pesewas' => ['nullable', 'integer', 'min:0', 'max:'.self::MAX],
            'security_pesewas' => ['nullable', 'integer', 'min:0', 'max:'.self::MAX],
            'payment_plan' => ['required', Rule::in(['full', 'deposit'])],
            'deposit_pesewas' => ['nullable', 'required_if:payment_plan,deposit', 'integer', 'min:1', 'max:'.self::MAX],
            'balance_due_at' => ['nullable', 'date'],
            'security_due_at' => ['nullable', 'date'],
            'cancellation_terms' => ['required', 'string', 'max:4000'],
            'support_contact' => ['required', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'payment_plan.in' => 'Choose full payment or a deposit.',
            'deposit_pesewas.required_if' => 'Enter the deposit amount.',
            'deposit_pesewas.min' => 'A deposit is at least one pesewa.',
            'cancellation_terms.required' => 'State your actual cancellation and refund terms.',
            'support_contact.required' => 'Give the buyer a way to reach you about this booking.',
        ];
    }
}
