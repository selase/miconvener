<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Lead;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Lead> */
final class LeadFactory extends Factory
{
    protected $model = Lead::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->name(),
            'email' => $this->faker->safeEmail(),
            'company' => $this->faker->company(),
            'message' => $this->faker->paragraph(),
            'status' => 'new',
            'ip_address' => $this->faker->ipv4(),
            'meta' => [],
        ];
    }
}
