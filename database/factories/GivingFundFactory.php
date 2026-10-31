<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\GivingFund;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<GivingFund> */
final class GivingFundFactory extends Factory
{
    protected $model = GivingFund::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => 'Community fund',
            'slug' => fake()->unique()->slug(),
            'currency' => 'GHS',
            'kind' => 'open',
            'state' => 'draft',
            'minimum_amount' => 100,
        ];
    }
}
