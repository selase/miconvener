<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enum\TenantStatusEnum;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Tenant>
 */
final class TenantFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition()
    {
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name),
            'email' => fake()->unique()->companyEmail(),
            'status' => TenantStatusEnum::ACTIVE,
            'isolation_mode' => 'shared',
            'db_driver' => 'pgsql',
            'country' => fake()->country(),
            'city' => fake()->city(),
            'state' => fake()->state(),
        ];
    }
}
