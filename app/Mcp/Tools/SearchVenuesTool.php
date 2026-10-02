<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Models\StoreListing;
use App\Services\Marketplace\MarketplaceSearchService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
final class SearchVenuesTool extends Tool
{
    protected string $name = 'search_venues';

    protected string $title = 'Search Venue Spaces';

    public function __construct(
        private readonly MarketplaceSearchService $searchService = new MarketplaceSearchService,
    ) {}

    public function description(): string
    {
        return 'Search and discover event venue spaces in Ghana using natural language query, city, minimum capacity, budget, or amenity criteria.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->description('Natural language query or keywords describing desired space, atmosphere, or requirements (e.g., "executive boardroom with ocean view and backup power")'),
            'city' => $schema->string()->description('City name (e.g., "Accra", "Kumasi", "Takoradi")'),
            'min_capacity' => $schema->integer()->min(1)->description('Minimum seating capacity required'),
            'capacity_style' => $schema->string()->description('Seating arrangement style: "banquet", "theater", "cocktail", "classroom"'),
            'max_price_ghs' => $schema->number()->description('Maximum rental budget in Ghana Cedis (GHS)'),
            'limit' => $schema->integer()->min(1)->max(30)->description('Maximum number of results to return (default: 10)'),
        ];
    }

    public function handle(Request $request): Response
    {
        $request->validate([
            'query' => ['nullable', 'string', 'max:1000'],
            'city' => ['nullable', 'string', 'max:100'],
            'min_capacity' => ['nullable', 'integer', 'min:1'],
            'capacity_style' => ['nullable', 'string', 'in:banquet,theater,cocktail,classroom'],
            'max_price_ghs' => ['nullable', 'numeric', 'min:0'],
            'limit' => ['nullable', 'integer', 'between:1,30'],
        ]);

        $filters = [];

        if ($request->has('query') && filled($request->get('query'))) {
            $filters['ai_prompt'] = (string) $request->get('query');
        }

        if ($request->has('city') && filled($request->get('city'))) {
            $filters['city'] = (string) $request->get('city');
        }

        if ($request->has('min_capacity') && filled($request->get('min_capacity'))) {
            $filters['min_capacity'] = (int) $request->get('min_capacity');
        }

        if ($request->has('capacity_style') && filled($request->get('capacity_style'))) {
            $filters['capacity_style'] = (string) $request->get('capacity_style');
        }

        if ($request->has('max_price_ghs') && filled($request->get('max_price_ghs'))) {
            $filters['max_price'] = (float) $request->get('max_price_ghs');
        }

        $limit = (int) $request->get('limit', 10);
        $paginator = $this->searchService->searchVenues($filters, $limit);

        $results = collect($paginator->items())->map(function (StoreListing $listing): array {
            $priceGhs = $listing->rental_price_pesewas > 0 ? $listing->rental_price_pesewas / 100 : 0;
            $pricingModel = str_replace('_', ' ', $listing->pricing_model ?? 'per day');

            return [
                'id' => $listing->id,
                'title' => $listing->title,
                'slug' => $listing->slug,
                'host' => [
                    'name' => $listing->shop?->name,
                    'city' => $listing->shop?->city,
                    'address' => $listing->shop?->address,
                    'is_verified' => $listing->shop?->isVerified() ?? false,
                ],
                'pricing' => [
                    'price_ghs' => $priceGhs,
                    'pricing_model' => $pricingModel,
                    'is_on_request' => $listing->isPriceOnRequest(),
                    'formatted' => $listing->isPriceOnRequest() ? 'Price on Request' : "GHS {$priceGhs} {$pricingModel}",
                ],
                'capacity' => $listing->capacity_breakdown ?? [],
                'floor_area_sqm' => $listing->floor_area_sqm,
                'similarity_match' => $listing->similarity_percentage ? "{$listing->similarity_percentage}%" : null,
                'url' => route('marketplace.venues.show', $listing->slug),
            ];
        })->all();

        return Response::text((string) json_encode([
            'total_found' => $paginator->total(),
            'returned_count' => count($results),
            'venues' => $results,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
