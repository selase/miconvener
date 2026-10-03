<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant\Venue;

use App\Models\Tenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateVenueProfileRequest extends FormRequest
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

        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', Rule::unique('landlord.shops', 'slug')->ignore($shopId)],
            'description' => ['nullable', 'string', 'max:3000'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['required', 'string', 'max:50'],
            'address' => ['required', 'string', 'max:500'],
            'city' => ['required', 'string', 'max:100'],
            'region' => ['required', 'string', 'max:100'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'vendor_categories' => ['nullable', 'array'],
            'vendor_categories.*' => ['string', 'max:64'],
            'business_registration_number' => ['nullable', 'string', 'max:100'],
            'tax_id' => ['nullable', 'string', 'max:100'],
            'past_clients' => ['nullable', 'array', 'max:50'],
            'past_clients.*.name' => ['required_with:past_clients', 'string', 'max:255'],
            'past_clients.*.event' => ['nullable', 'string', 'max:255'],
        ];
    }
}
