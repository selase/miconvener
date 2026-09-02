<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enum\UsageMetric;
use App\Models\Tenant;
use App\Models\UsageEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<UsageEvent> */
final class UsageEventFactory extends Factory
{
    protected $model = UsageEvent::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'type' => $this->faker->randomElement(UsageMetric::cases()),
            'quantity' => $this->faker->randomFloat(4, 1, 1000),
            'occurred_at' => now(),
            'meta' => [],
        ];
    }
}
