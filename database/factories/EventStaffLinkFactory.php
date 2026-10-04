<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Event;
use App\Models\EventStaffLink;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<EventStaffLink> */
final class EventStaffLinkFactory extends Factory
{
    protected $model = EventStaffLink::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'event_id' => Event::factory(),
            'name' => 'Gate '.fake()->randomLetter().' – '.fake()->firstName(),
            'token' => EventStaffLink::newToken(),
            'can_check_in' => true,
            'can_handle_requests' => false,
        ];
    }

    public function handlesRequests(): self
    {
        return $this->state(['can_handle_requests' => true]);
    }

    public function revoked(): self
    {
        return $this->state(['revoked_at' => now()]);
    }
}
