<?php

declare(strict_types=1);

namespace App\Services\Marketplace;

use App\Models\StoreListing;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Embeddings;
use Throwable;

final class VenueEmbeddingService
{
    public const int VECTOR_DIMENSIONS = 1536;

    /**
     * Synthesize a comprehensive semantic document describing the venue space.
     */
    public function synthesizeDocument(StoreListing $listing): string
    {
        $listing->loadMissing(['shop', 'amenities.amenity']);

        $parts = [];

        // Title and Merchant Identity
        $parts[] = "Venue Space: {$listing->title}";

        if ($listing->shop) {
            $parts[] = "Host / Venue Name: {$listing->shop->name}";
            $parts[] = "Location: {$listing->shop->address}, {$listing->shop->city}, {$listing->shop->region}, Ghana";
            if (! empty($listing->shop->description)) {
                $parts[] = "Venue Overview: {$listing->shop->description}";
            }
        }

        // Space Specifications
        if ($listing->floor_area_sqm) {
            $parts[] = "Floor Area: {$listing->floor_area_sqm} square meters";
        }

        if ($listing->ceiling_height_meters) {
            $parts[] = "Ceiling Height: {$listing->ceiling_height_meters} meters";
        }

        // Capacities
        $capacities = $listing->capacity_breakdown ?? [];
        if (! empty($capacities)) {
            $capDescriptions = [];
            foreach ($capacities as $style => $count) {
                if (is_numeric($count) && (int) $count > 0) {
                    $styleLabel = ucfirst((string) $style);
                    $capDescriptions[] = "{$count} guests in {$styleLabel} seating";
                }
            }
            if (! empty($capDescriptions)) {
                $parts[] = 'Seating Capacity: '.implode(', ', $capDescriptions);
            }
        }

        // Pricing & Visibility
        if ($listing->isPriceOnRequest()) {
            $parts[] = 'Pricing: Available upon request from host';
        } else {
            $priceGhs = number_format($listing->rental_price_pesewas / 100, 2);
            $pricingModel = str_replace('_', ' ', $listing->pricing_model ?? 'per day');
            $parts[] = "Rental Rate: GHS {$priceGhs} {$pricingModel}";
        }

        // Included vs Excluded Amenities
        $includedAmenities = [];
        $excludedAmenities = [];

        foreach ($listing->amenities as $listingAmenity) {
            if ($listingAmenity->amenity) {
                $name = $listingAmenity->amenity->name;
                if ($listingAmenity->is_included) {
                    $includedAmenities[] = $name;
                } else {
                    $excludedAmenities[] = $name;
                }
            }
        }

        if (! empty($includedAmenities)) {
            $parts[] = 'Included Amenities & Equipment: '.implode(', ', $includedAmenities);
        }

        if (! empty($excludedAmenities)) {
            $parts[] = 'Additional Paid Services / Excluded: '.implode(', ', $excludedAmenities);
        }

        // Rules & Policies
        $rules = $listing->rules_and_policies ?? [];
        if (! empty($rules)) {
            $ruleItems = [];
            foreach ($rules as $k => $v) {
                $label = ucfirst(str_replace('_', ' ', (string) $k));
                if (is_bool($v)) {
                    $ruleItems[] = $v ? "{$label} allowed" : "No {$label}";
                } elseif (is_string($v) || is_numeric($v)) {
                    $ruleItems[] = "{$label}: {$v}";
                }
            }
            if (! empty($ruleItems)) {
                $parts[] = 'Policies & House Rules: '.implode('; ', $ruleItems);
            }
        }

        // Description
        if (! empty($listing->description)) {
            $parts[] = "Space Description: {$listing->description}";
        }

        return implode("\n", $parts);
    }

    /**
     * Generate a 1536-dimensional float vector for a given prompt or document.
     * Uses Laravel AI SDK if an API key is available or if faked; otherwise uses a deterministic unit vector.
     *
     * @return array<int, float>
     */
    public function generateEmbedding(string $text): array
    {
        $text = mb_trim($text);
        if ($text === '') {
            $vec = array_fill(0, self::VECTOR_DIMENSIONS, 0.0);
            $vec[0] = 1.0;

            return $vec;
        }

        // 1. If Embeddings is faked (in Pest tests with Embeddings::fake()), use Laravel AI SDK
        if (Embeddings::isFaked()) {
            return Embeddings::for([$text])->dimensions(self::VECTOR_DIMENSIONS)->generate()->first();
        }

        $cacheKey = 'venue_embedding:'.hash('sha256', mb_strtolower($text));

        /** @var array<int, float> */
        return Cache::remember($cacheKey, now()->addDays(7), function () use ($text): array {
            // 2. If an API key is configured for the default provider, call the official Laravel AI SDK
            $providerName = (string) config('ai.default_for_embeddings', 'openai');
            $apiKey = config("ai.providers.{$providerName}.key");

            if (! empty($apiKey)) {
                try {
                    return Embeddings::for([$text])
                        ->dimensions(self::VECTOR_DIMENSIONS)
                        ->generate()
                        ->first();
                } catch (Throwable $e) {
                    Log::warning("Laravel AI embedding generation failed for provider {$providerName}, falling back to deterministic vector: {$e->getMessage()}");
                }
            }

            // 3. Fallback: Deterministic L2-normalized unit vector for offline/testing development
            return $this->generateDeterministicVector($text);
        });
    }

    /**
     * Compute and persist the vector embedding for a venue listing.
     */
    public function embedListing(StoreListing $listing): bool
    {
        $document = $this->synthesizeDocument($listing);
        $vector = $this->generateEmbedding($document);

        $listing->embedding = $vector;

        return $listing->save();
    }

    /**
     * Batch embed all active published venue spaces in the marketplace.
     */
    public function embedAllPublished(): int
    {
        $listings = StoreListing::query()
            ->where('listing_kind', StoreListing::KIND_VENUE)
            ->where('status', StoreListing::STATUS_PUBLISHED)
            ->with(['shop', 'amenities.amenity'])
            ->get();

        $count = 0;
        foreach ($listings as $listing) {
            if ($this->embedListing($listing)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Generates a deterministic, normalized 1536-dimensional float vector based on word frequency hashing.
     *
     * @return array<int, float>
     */
    public function generateDeterministicVector(string $text): array
    {
        $dim = self::VECTOR_DIMENSIONS;
        $vec = array_fill(0, $dim, 0.0);

        $words = preg_split('/[^a-zA-Z0-9]+/', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY);
        if (empty($words)) {
            $vec[0] = 1.0;

            return $vec;
        }

        foreach ($words as $word) {
            $h1 = abs(crc32($word)) % $dim;
            $h2 = abs(crc32($word.'_salt')) % $dim;
            $vec[$h1] += 1.0;
            $vec[$h2] += 0.5;
        }

        $sumSquares = 0.0;
        foreach ($vec as $v) {
            $sumSquares += $v * $v;
        }

        $norm = sqrt($sumSquares);
        if ($norm > 0) {
            return array_map(fn (float $v): float => round($v / $norm, 6), $vec);
        }

        $vec[0] = 1.0;

        return $vec;
    }
}
