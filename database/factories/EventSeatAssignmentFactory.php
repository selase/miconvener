<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\EventSeatAssignment;
use App\Models\EventVenueRoom;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<EventSeatAssignment> */
final class EventSeatAssignmentFactory extends Factory
{
    protected $model = EventSeatAssignment::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'event_id' => Event::factory(),
            'room_id' => EventVenueRoom::factory(),
            'registration_id' => EventRegistration::factory(),
            'seat_label' => 'A-01',
        ];
    }
}
