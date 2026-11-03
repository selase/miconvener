<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Event;
use App\Models\EventAttendeeProfile;
use App\Models\EventRegistration;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<EventAttendeeProfile> */
final class EventAttendeeProfileFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'event_id' => fn (array $attributes) => Event::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'registration_id' => fn (array $attributes) => EventRegistration::factory()->create(['tenant_id' => $attributes['tenant_id'], 'event_id' => $attributes['event_id']])->id,
            'owner_hash' => hash('sha256', fake()->unique()->safeEmail()),
            'state' => 'private',
        ];
    }

    public function forRegistration(EventRegistration $registration): static
    {
        return $this->state(['tenant_id' => $registration->tenant_id, 'event_id' => $registration->event_id, 'registration_id' => $registration->id]);
    }
}
