<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use App\Models\TenantFeature;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TenantFeature> */
final class TenantFeatureFactory extends Factory
{
    protected $model = TenantFeature::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'feature_key' => $this->faker->slug(2),
            'enabled' => true,
            'meta' => ['type' => 'boolean', 'source' => 'package'],
        ];
    }

    public function metered(int $limit = 100): static
    {
        return $this->state([
            'meta' => ['type' => 'limit', 'value' => $limit, 'source' => 'package'],
        ]);
    }

    public function disabled(): static
    {
        return $this->state(['enabled' => false]);
    }
}
