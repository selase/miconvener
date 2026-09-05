<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Event;
use App\Models\EventVenueRoom;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<EventVenueRoom> */
final class EventVenueRoomFactory extends Factory
{
    protected $model = EventVenueRoom::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'event_id' => Event::factory(),
            'name' => 'Main Hall',
            'rows' => 6,
            'seats_per_row' => 10,
        ];
    }
}
