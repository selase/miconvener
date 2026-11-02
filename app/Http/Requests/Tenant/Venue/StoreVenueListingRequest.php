<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant\Venue;

use App\Models\StoreListing;
use App\Models\Tenant;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreVenueListingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage venue') ?? false;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        /** @var Tenant|null $tenant */
        $tenant = app(\App\Services\Tenancy\TenantContext::class)->getTenant();
        $shopId = $tenant?->shop?->id;
        $listingId = $this->route('listing') instanceof StoreListing
            ? $this->route('listing')->id
            : $this->route('listing');

        return [
            'title' => ['required', 'string', 'max:255'],
            'slug' => [
                'required',
                'string',
                'max:255',
                Rule::unique('landlord.store_listings', 'slug')->ignore($listingId),
            ],
            'description' => ['nullable', 'string'],
            'rental_price' => ['required', 'numeric', 'min:0'],
            'pricing_model' => [
                'required',
                'string',
                Rule::in([
                    StoreListing::PRICING_MODEL_PER_DAY,
                    StoreListing::PRICING_MODEL_PER_HALF_DAY,
                    StoreListing::PRICING_MODEL_PER_HOUR,
                    StoreListing::PRICING_MODEL_FLAT_RATE,
                ]),
            ],
            'price_visibility' => [
                'required',
                'string',
                Rule::in([
                    StoreListing::PRICE_VISIBILITY_PUBLIC,
                    StoreListing::PRICE_VISIBILITY_ON_REQUEST,
                ]),
            ],
            'security_deposit' => ['nullable', 'numeric', 'min:0'],
            'capacity_breakdown' => ['nullable', 'array'],
            'capacity_breakdown.theater' => ['nullable', 'integer', 'min:0'],
            'capacity_breakdown.banquet' => ['nullable', 'integer', 'min:0'],
            'capacity_breakdown.cocktail' => ['nullable', 'integer', 'min:0'],
            'capacity_breakdown.classroom' => ['nullable', 'integer', 'min:0'],
            'floor_area_sqm' => ['nullable', 'numeric', 'min:0'],
            'ceiling_height_meters' => ['nullable', 'numeric', 'min:0'],
            'rules_and_policies' => ['nullable', 'array'],
            'status' => [
                'required',
                'string',
                Rule::in([
                    StoreListing::STATUS_DRAFT,
                    StoreListing::STATUS_PUBLISHED,
                    StoreListing::STATUS_SUSPENDED,
                ]),
            ],
            'audience' => ['sometimes', 'string', Rule::in([StoreListing::AUDIENCE_PUBLIC, StoreListing::AUDIENCE_STAFF])],
            'requires_approval' => ['sometimes', 'boolean'],
            'public_booking_policy' => ['nullable', 'array:default_hold_hours,timezone'],
            'public_booking_policy.default_hold_hours' => ['sometimes', 'integer', 'min:1', 'max:168'],
            'public_booking_policy.timezone' => ['sometimes', 'string', 'timezone:all'],
            'amenities' => ['nullable', 'array'],
            'amenities.*.amenity_id' => ['required', 'uuid', 'exists:landlord.store_amenities,id'],
            'amenities.*.is_included' => ['required', 'boolean'],
            'amenities.*.notes' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * A room can be made staff-only only on a plan with staff room booking.
     * Making one public is always allowed, so an organisation that has left
     * Enterprise can always open its rooms again.
     *
     * @return array<int, Closure(\Illuminate\Validation\Validator): void>
     */
    public function after(): array
    {
        return [function (\Illuminate\Validation\Validator $validator): void {
            $tenant = app(\App\Services\Tenancy\TenantContext::class)->getTenant();

            if ($this->input('audience') === StoreListing::AUDIENCE_STAFF && ! $tenant?->featureEnabled(\App\Services\Tenancy\FeatureService::FEATURE_STAFF_ROOM_BOOKING)) {
                $validator->errors()->add('audience', 'Staff-only rooms are available on the Enterprise plan.');
            }
        }];
    }
}
