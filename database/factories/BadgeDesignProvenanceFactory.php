<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\BadgeDesignProvenance;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<BadgeDesignProvenance> */
final class BadgeDesignProvenanceFactory extends Factory
{
    protected $model = BadgeDesignProvenance::class;

    public function definition(): array
    {
        return [
            'family_id' => fn () => \App\Models\BadgeLibraryDesign::query()->create(['scope' => 'platform', 'name' => 'Premium source', 'price_pesewas' => 5000])->id,
            'source_type' => 'version',
            'source_id' => fn (array $attributes) => \App\Models\BadgeLibraryDesign::query()->whereKey($attributes['family_id'])->firstOrFail()->versions()->create(['version' => 1, 'snapshot' => []])->id,
        ];
    }
}
