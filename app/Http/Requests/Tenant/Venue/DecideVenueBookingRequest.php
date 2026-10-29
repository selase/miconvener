<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant\Venue;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A host's decision on an approval-first venue request. The reason is shown to
 * the requester, so declining or releasing needs one.
 */
final class DecideVenueBookingRequest extends FormRequest
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
        $needsReason = in_array($this->route()?->getActionMethod(), ['decline', 'release'], true);

        return [
            'operation_key' => ['required', 'uuid'],
            'hold_hours' => ['nullable', 'integer', 'min:1', 'max:168'],
            'reason' => [Rule::requiredIf($needsReason), 'nullable', 'string', 'min:1', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'hold_hours.min' => 'A hold lasts at least 1 hour.',
            'hold_hours.max' => 'A hold lasts at most 168 hours (7 days).',
            'reason.required' => 'Give the requester a reason.',
            'reason.max' => 'Keep the reason under 1000 characters.',
        ];
    }
}
