<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\StoreListing;
use App\Models\StoreMerchandise;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<StoreMerchandise> */
final class StoreMerchandiseFactory extends Factory
{
    protected $model = StoreMerchandise::class;

    public function definition(): array
    {
        return [
            'listing_id' => StoreListing::factory()->state(['listing_kind' => StoreListing::KIND_MERCHANDISE]),
            'shop_id' => fn (array $attributes) => StoreListing::withoutGlobalScopes()->whereKey($attributes['listing_id'])->firstOrFail()->shop_id,
            'visibility' => 'public', 'currency' => 'GHS', 'collection_instructions' => 'Collect from the organisation office.',
        ];
    }
}
