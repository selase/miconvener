<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enum\UsageMetric;
use App\Models\Tenant;
use App\Models\UsageLimit;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<UsageLimit> */
final class UsageLimitFactory extends Factory
{
    protected $model = UsageLimit::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'metric' => $this->faker->randomElement(UsageMetric::cases()),
            'limit_value' => $this->faker->randomFloat(4, 100, 100000),
            'period' => $this->faker->randomElement(['hourly', 'daily', 'monthly']),
            'alert_threshold' => 80,
            'block_on_limit' => true,
            'is_active' => true,
            'last_alert_at' => null,
        ];
    }
}
