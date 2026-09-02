<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enum\UsageMetric;
use App\Models\Tenant;
use App\Models\UsageRollup;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<UsageRollup> */
final class UsageRollupFactory extends Factory
{
    protected $model = UsageRollup::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'metric' => $this->faker->randomElement(UsageMetric::cases()),
            'period' => $this->faker->randomElement(['minute', 'hour', 'day']),
            'period_start' => now()->startOfHour(),
            'dimensions' => [],
            'value' => $this->faker->randomFloat(4, 0, 10000),
            'count' => $this->faker->numberBetween(1, 500),
        ];
    }
}
