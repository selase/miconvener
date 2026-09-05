<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Speaker;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Speaker> */
final class SpeakerFactory extends Factory
{
    protected $model = Speaker::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => $this->faker->name(),
            'title' => $this->faker->jobTitle(),
            'organization' => $this->faker->company(),
            'bio' => $this->faker->paragraph(),
        ];
    }
}
