<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\StoreMerchandise;
use App\Models\StoreVariant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<StoreVariant> */
final class StoreVariantFactory extends Factory
{
    protected $model = StoreVariant::class;

    public function definition(): array
    {
        return [
            'listing_id' => fn () => StoreMerchandise::factory()->create()->listing_id,
            'shop_id' => fn (array $attributes) => StoreMerchandise::query()->where('listing_id', $attributes['listing_id'])->firstOrFail()->shop_id,
            'sku' => fake()->unique()->bothify('SKU-????-####'), 'options' => [],
            'price_pesewas' => 12000, 'on_hand' => 0, 'is_active' => true,
        ];
    }
}
