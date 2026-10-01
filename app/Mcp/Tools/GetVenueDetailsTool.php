<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Models\StoreListing;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
final class GetVenueDetailsTool extends Tool
{
    protected string $name = 'get_venue_details';

    protected string $title = 'Get Venue Space Details';

    public function description(): string
    {
        return 'Retrieve comprehensive specifications, included vs excluded amenities, dimensions, policies, and host contact for a specific venue space by its slug.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'slug' => $schema->string()->description('The unique slug of the venue space (e.g., "executive-ocean-boardroom" or "the-grand-ballroom")')->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $request->validate([
            'slug' => ['required', 'string', 'max:255'],
        ]);

        $slug = (string) $request->get('slug');

        /** @var StoreListing|null $listing */
        $listing = StoreListing::query()
            ->with(['shop', 'amenities.amenity', 'media'])
            ->where('listing_kind', StoreListing::KIND_VENUE)
            ->where('slug', $slug)
            ->where('status', StoreListing::STATUS_PUBLISHED)
            ->whereHas('shop', function ($q): void {
                $q->where('is_active', true);
            })
            ->first();

        if ($listing === null) {
            return Response::error("Venue space with slug '{$slug}' was not found or is currently inactive.");
        }

        $includedAmenities = [];
        $excludedAmenities = [];

        foreach ($listing->amenities as $listingAmenity) {
            if ($listingAmenity->amenity) {
                $item = [
                    'name' => $listingAmenity->amenity->name,
                    'category' => $listingAmenity->amenity->category,
                    'notes' => $listingAmenity->notes,
                ];

                if ($listingAmenity->is_included) {
                    $includedAmenities[] = $item;
                } else {
                    $excludedAmenities[] = $item;
                }
            }
        }

        $priceGhs = $listing->rental_price_pesewas > 0 ? $listing->rental_price_pesewas / 100 : 0;
        $depositGhs = $listing->security_deposit_pesewas ? $listing->security_deposit_pesewas / 100 : 0;

        $payload = [
            'id' => $listing->id,
            'title' => $listing->title,
            'slug' => $listing->slug,
            'description' => $listing->description,
            'pricing' => [
                'rental_price_ghs' => $priceGhs,
                'pricing_model' => str_replace('_', ' ', $listing->pricing_model ?? 'per day'),
                'is_price_on_request' => $listing->isPriceOnRequest(),
                'security_deposit_ghs' => $depositGhs,
            ],
            'specifications' => [
                'floor_area_sqm' => $listing->floor_area_sqm,
                'ceiling_height_meters' => $listing->ceiling_height_meters,
                'capacity_breakdown' => $listing->capacity_breakdown ?? [],
            ],
            'amenities' => [
                'included_with_rental' => $includedAmenities,
                'extra_or_excluded' => $excludedAmenities,
            ],
            'rules_and_policies' => $listing->rules_and_policies ?? [],
            'host' => [
                'name' => $listing->shop?->name,
                'email' => $listing->shop?->email,
                'phone' => $listing->shop?->phone,
                'address' => $listing->shop?->address,
                'city' => $listing->shop?->city,
                'region' => $listing->shop?->region,
                'is_verified' => $listing->shop?->isVerified() ?? false,
            ],
            'public_url' => route('marketplace.venues.show', $listing->slug),
        ];

        return Response::text((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
