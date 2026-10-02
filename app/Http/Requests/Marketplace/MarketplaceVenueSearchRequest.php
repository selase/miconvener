<?php

declare(strict_types=1);

namespace App\Http\Requests\Marketplace;

use Illuminate\Foundation\Http\FormRequest;

final class MarketplaceVenueSearchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:255'],
            'ai_prompt' => ['nullable', 'string', 'max:1000'],
            'city' => ['nullable', 'string', 'max:100'],
            'region' => ['nullable', 'string', 'max:100'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
            'radius' => ['nullable', 'numeric', 'min:1', 'max:500'],
            'min_capacity' => ['nullable', 'integer', 'min:1', 'max:50000'],
            'capacity_style' => ['nullable', 'string', 'in:banquet,theater,cocktail,classroom'],
            'min_price' => ['nullable', 'numeric', 'min:0'],
            'max_price' => ['nullable', 'numeric', 'min:0'],
            'price_visibility' => ['nullable', 'string', 'in:public,on_request'],
            'amenities' => ['nullable'],
            'sort' => ['nullable', 'string', 'in:recommended,ai_match,price_asc,price_desc,capacity_desc,newest'],
        ];
    }
}
