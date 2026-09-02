<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tax;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Tax> */
final class TaxFactory extends Factory
{
    protected $model = Tax::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->randomElement(['VAT', 'GST', 'Sales Tax', 'Service Tax']),
            'rate' => $this->faker->randomFloat(2, 1, 25),
            'is_compound' => false,
            'priority' => 1,
            'is_active' => true,
        ];
    }

    public function compound(): static
    {
        return $this->state([
            'is_compound' => true,
            'priority' => 2,
        ]);
    }
}
