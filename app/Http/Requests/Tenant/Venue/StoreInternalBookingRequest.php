<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant\Venue;

use App\Services\Tenancy\FeatureService;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A team member booking a room for a staff member, who may be named from the
 * staff list or entered by name and email (and tagged as staff on the way).
 */
final class StoreInternalBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        $tenant = app(TenantContext::class)->getTenant();

        return (bool) $this->user()?->can('manage room bookings') && (bool) $tenant?->featureEnabled(FeatureService::FEATURE_STAFF_ROOM_BOOKING);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'store_listing_id' => ['required', 'uuid'],
            'member_id' => ['nullable', 'uuid', 'required_without:staff_email'],
            'staff_name' => ['nullable', 'string', 'max:255', 'required_without:member_id'],
            'staff_email' => ['nullable', 'email', 'max:255', 'required_without:member_id'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'purpose' => ['required', 'string', 'max:500'],
            'department' => ['nullable', 'string', 'max:255'],
            'guest_count' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'confirm' => ['nullable', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'member_id.required_without' => 'Choose a staff member, or enter their name and email.',
            'staff_email.required_without' => 'Enter an email address, or choose a staff member.',
            'staff_name.required_without' => 'Enter a name, or choose a staff member.',
            'purpose.required' => 'Say what the room is for.',
        ];
    }
}
