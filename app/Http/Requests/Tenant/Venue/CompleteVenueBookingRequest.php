<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant\Venue;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The host records that a finished, fully paid event is complete, which releases its earnings.
 */
final class CompleteVenueBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('release venue settlements');
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
            'reason.required' => 'Add a note that the event is complete.',
        ];
    }
}
