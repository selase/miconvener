<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Event;
use App\Models\EventTicketType;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<EventTicketType> */
final class EventTicketTypeFactory extends Factory
{
    protected $model = EventTicketType::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'event_id' => Event::factory(),
            'name' => $this->faker->randomElement(['General Admission', 'In-Person', 'Virtual', 'VIP']),
            'price' => 0,
            'is_active' => true,
        ];
    }

    public function paid(int $amount = 5000): static
    {
        return $this->state(['price' => $amount]);
    }

    public function withCapacity(int $capacity): static
    {
        return $this->state(['capacity' => $capacity]);
    }
}
