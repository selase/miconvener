<?php

declare(strict_types=1);

namespace App\Http\Requests\Marketplace;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A public, approval-first venue request. Times arrive as ISO 8601 with a UTC
 * offset (the service enforces it), and the operation key makes a resubmit safe.
 */
final class StoreVenueBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'operation_key' => ['required', 'uuid'],
            'planner_name' => ['required', 'string', 'max:255'],
            'planner_email' => ['required', 'email', 'max:255'],
            'planner_phone' => ['nullable', 'string', 'max:50'],
            'planner_company' => ['nullable', 'string', 'max:255'],
            'event_type' => ['required', 'string', 'max:100'],
            'guest_count' => ['required', 'integer', 'min:1', 'max:100000'],
            'layout_style' => ['required', 'string', 'in:banquet,theater,cocktail,classroom,boardroom'],
            'starts_at' => ['required', 'string', 'max:40'],
            'ends_at' => ['required', 'string', 'max:40'],
            'special_requests' => ['nullable', 'string', 'max:2000'],
            'agreed_to_terms' => ['accepted'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'operation_key.required' => 'Reload the page and send the request again.',
            'operation_key.uuid' => 'Reload the page and send the request again.',
            'agreed_to_terms.accepted' => 'Accept the venue terms to send your request.',
        ];
    }
}
