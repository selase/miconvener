<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use App\Models\TenantFeatureUsage;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TenantFeatureUsage> */
final class TenantFeatureUsageFactory extends Factory
{
    protected $model = TenantFeatureUsage::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'feature_slug' => $this->faker->slug(2),
            'used_count' => $this->faker->numberBetween(0, 500),
            'period_start' => null,
            'period_end' => null,
        ];
    }
}
