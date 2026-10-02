<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant\Venue;

use App\Models\StoreListing;
use App\Models\Tenant;
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
            'amenities' => ['nullable', 'array'],
            'amenities.*.amenity_id' => ['required', 'uuid', 'exists:landlord.store_amenities,id'],
            'amenities.*.is_included' => ['required', 'boolean'],
            'amenities.*.notes' => ['nullable', 'string', 'max:255'],
        ];
    }
}
