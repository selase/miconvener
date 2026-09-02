<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enum\UsageMetric;
use App\Models\Package;
use App\Models\UsagePrice;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<UsagePrice> */
final class UsagePriceFactory extends Factory
{
    protected $model = UsagePrice::class;

    public function definition(): array
    {
        return [
            'target_type' => Package::class,
            'target_id' => Package::factory(),
            'metric' => $this->faker->randomElement(UsageMetric::cases()),
            'unit_price' => $this->faker->randomFloat(6, 0.001, 1),
            'unit_quantity' => 1,
            'currency' => 'USD',
        ];
    }
}
