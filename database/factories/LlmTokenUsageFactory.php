<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\LlmTokenUsage;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<LlmTokenUsage> */
final class LlmTokenUsageFactory extends Factory
{
    protected $model = LlmTokenUsage::class;

    public function definition(): array
    {
        $promptTokens = $this->faker->numberBetween(50, 5000);
        $completionTokens = $this->faker->numberBetween(50, 5000);

        return [
            'tenant_id' => Tenant::factory(),
            'user_id' => User::factory(),
            'model' => $this->faker->randomElement(['claude-sonnet-4-20250514', 'claude-haiku-4-20250414', 'gpt-4o']),
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $completionTokens,
            'total_tokens' => $promptTokens + $completionTokens,
            'cost_usd' => round(($promptTokens * 0.003 + $completionTokens * 0.015) / 1000, 6),
            'context' => ['purpose' => 'test'],
        ];
    }
}
