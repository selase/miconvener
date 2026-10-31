<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ExchangeRate;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ExchangeRate> */
final class ExchangeRateFactory extends Factory
{
    protected $model = ExchangeRate::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['base' => 'USD', 'quote' => 'GHS', 'rate' => 11.665, 'as_of' => now()->toDateString(), 'source' => 'bank_of_ghana', 'fetched_at' => now()];
    }
}
