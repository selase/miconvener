<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\LlmUsageSummary;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<LlmUsageSummary> */
final class LlmUsageSummaryFactory extends Factory
{
    protected $model = LlmUsageSummary::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'day' => $this->faker->dateTimeBetween('-30 days', 'now'),
            'total_prompt_tokens' => $this->faker->numberBetween(1000, 100000),
            'total_completion_tokens' => $this->faker->numberBetween(1000, 100000),
            'total_total_tokens' => $this->faker->numberBetween(2000, 200000),
            'total_cost_usd' => $this->faker->randomFloat(6, 0.1, 50),
            'request_count' => $this->faker->numberBetween(10, 500),
        ];
    }
}
