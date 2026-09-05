<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Event;
use App\Models\EventSession;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<EventSession> */
final class EventSessionFactory extends Factory
{
    protected $model = EventSession::class;

    public function definition(): array
    {
        $starts = $this->faker->dateTimeBetween('+1 week', '+1 week +2 days');

        return [
            'tenant_id' => Tenant::factory(),
            'event_id' => Event::factory(),
            'title' => $this->faker->sentence(4),
            'starts_at' => $starts,
            'ends_at' => (clone $starts)->modify('+1 hour'),
            'type' => 'session',
        ];
    }

    public function workshop(): static
    {
        return $this->state(['type' => EventSession::TYPE_WORKSHOP]);
    }
}
