<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Event;
use App\Models\EventPoll;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<EventPoll> */
final class EventPollFactory extends Factory
{
    protected $model = EventPoll::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'event_id' => Event::factory(),
            'question' => $this->faker->sentence(6).'?',
            'type' => EventPoll::TYPE_MULTIPLE_CHOICE,
            'status' => EventPoll::STATUS_DRAFT,
        ];
    }

    public function live(): static
    {
        return $this->state(['status' => EventPoll::STATUS_LIVE, 'went_live_at' => now()]);
    }

    public function open(): static
    {
        return $this->state(['type' => EventPoll::TYPE_OPEN]);
    }

    public function quiz(): static
    {
        return $this->state(['type' => EventPoll::TYPE_QUIZ, 'timer_seconds' => 20, 'points' => 10]);
    }
}
