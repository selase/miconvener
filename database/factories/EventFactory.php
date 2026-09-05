<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Event;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Event> */
final class EventFactory extends Factory
{
    protected $model = Event::class;

    public function definition(): array
    {
        $name = $this->faker->sentence(4);
        $startsAt = $this->faker->dateTimeBetween('+1 week', '+2 months');

        return [
            'tenant_id' => Tenant::factory(),
            'name' => $name,
            'slug' => Str::slug($name).'-'.$this->faker->unique()->numberBetween(1000, 9999),
            'description' => $this->faker->paragraph(),
            'status' => Event::STATUS_DRAFT,
            'starts_at' => $startsAt,
            'ends_at' => (clone $startsAt)->modify('+3 hours'),
            'timezone' => 'Africa/Accra',
            'location_type' => Event::LOCATION_IN_PERSON,
            'address' => $this->faker->address(),
            'capacity' => $this->faker->numberBetween(50, 500),
            'ticket_price' => 0,
            'currency' => 'GHS',
        ];
    }

    public function published(): static
    {
        return $this->state(['status' => Event::STATUS_PUBLISHED]);
    }

    public function paid(int $amount = 5000): static
    {
        return $this->state(['ticket_price' => $amount]);
    }

    public function virtual(): static
    {
        return $this->state([
            'location_type' => Event::LOCATION_VIRTUAL,
            'address' => null,
            'virtual_link' => $this->faker->url(),
        ]);
    }
}
