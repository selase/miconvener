<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant\Venue;

use App\Services\Tenancy\FeatureService;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Declining a request needs a reason, which the staff member is emailed.
 */
final class DeclineInternalBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        $tenant = app(TenantContext::class)->getTenant();

        return (bool) $this->user()?->can('manage room bookings') && (bool) $tenant?->featureEnabled(FeatureService::FEATURE_STAFF_ROOM_BOOKING);
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'max:1000']];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['reason.required' => 'Give a reason, so the staff member knows why.'];
    }
}
