<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant\Venue;

use App\Services\Tenancy\FeatureService;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The limits an organisation's staff book within: how long one booking can be,
 * how far ahead, and how many requests one person may have waiting.
 */
final class UpdateBookingLimitsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $tenant = app(TenantContext::class)->getTenant();

        return (bool) $this->user()?->can('manage room bookings') && (bool) $tenant?->featureEnabled(FeatureService::FEATURE_STAFF_ROOM_BOOKING);
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'max_hours' => ['required', 'integer', 'min:1', 'max:24'],
            'window_days' => ['required', 'integer', 'min:1', 'max:365'],
            'open_requests' => ['required', 'integer', 'min:1', 'max:50'],
        ];
    }
}
