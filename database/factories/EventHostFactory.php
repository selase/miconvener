<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Event;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<\App\Models\EventHost> */
final class EventHostFactory extends Factory
{
    public function definition(): array
    {
        return ['event_id' => Event::factory(), 'tenant_id' => fn (array $attributes) => Event::findOrFail($attributes['event_id'])->tenant_id, 'name' => fake()->company(), 'type' => 'organisation', 'sort_order' => 0];
    }
}
