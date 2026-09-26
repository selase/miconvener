<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Event;
use App\Models\PollDeck;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PollDeck> */
final class PollDeckFactory extends Factory
{
    protected $model = PollDeck::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'event_id' => Event::factory(),
            'title' => $this->faker->sentence(3),
            'join_code' => PollDeck::generateJoinCode(),
            'status' => PollDeck::STATUS_DRAFT,
        ];
    }

    public function live(): static
    {
        return $this->state(['status' => PollDeck::STATUS_LIVE]);
    }

    public function ended(): static
    {
        return $this->state(['status' => PollDeck::STATUS_ENDED]);
    }
}
