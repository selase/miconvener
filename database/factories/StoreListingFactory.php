<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Shop;
use App\Models\StoreListing;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<StoreListing>
 */
final class StoreListingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = fake()->unique()->words(3, true);

        return [
            'shop_id' => Shop::factory(),
            'listing_kind' => StoreListing::KIND_RENTAL,
            'category' => StoreListing::CATEGORY_OTHER,
            'title' => Str::title($title),
            'slug' => Str::slug($title).'-'.fake()->unique()->randomNumber(4),
            'description' => fake()->paragraph(),
            'rental_price_pesewas' => fake()->numberBetween(10, 500) * 1000,
            'pricing_model' => StoreListing::PRICING_MODEL_PER_DAY,
            'price_visibility' => StoreListing::PRICE_VISIBILITY_PUBLIC,
            'status' => StoreListing::STATUS_DRAFT,
            'sort_order' => 0,
            'is_bookable' => false,
        ];
    }

    public function published(): self
    {
        return $this->state(['status' => StoreListing::STATUS_PUBLISHED]);
    }
}
