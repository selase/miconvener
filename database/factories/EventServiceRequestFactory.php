<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\EventServiceRequest;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<EventServiceRequest> */
final class EventServiceRequestFactory extends Factory
{
    protected $model = EventServiceRequest::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'event_id' => Event::factory(),
            'registration_id' => EventRegistration::factory(),
            'type' => EventServiceRequest::TYPE_ASSISTANCE,
            'priority' => EventServiceRequest::PRIORITY_NORMAL,
            'status' => EventServiceRequest::STATUS_OPEN,
            'location' => 'Grand Ballroom',
        ];
    }
}
