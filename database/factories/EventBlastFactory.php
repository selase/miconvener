<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Event;
use App\Models\EventBlast;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<EventBlast> */
final class EventBlastFactory extends Factory
{
    protected $model = EventBlast::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'event_id' => Event::factory(),
            'subject' => $this->faker->sentence(4),
            'body' => $this->faker->paragraph(),
            'audience' => 'all',
            'recipients_count' => 0,
        ];
    }
}
