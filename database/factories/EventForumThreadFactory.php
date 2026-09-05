<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Event;
use App\Models\EventForumThread;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<EventForumThread> */
final class EventForumThreadFactory extends Factory
{
    protected $model = EventForumThread::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'event_id' => Event::factory(),
            'title' => $this->faker->sentence(6),
            'body' => $this->faker->paragraph(),
            'author_name' => $this->faker->name(),
            'author_email' => $this->faker->safeEmail(),
        ];
    }
}
