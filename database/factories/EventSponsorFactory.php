<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Event;
use App\Models\EventSponsor;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<EventSponsor> */
final class EventSponsorFactory extends Factory
{
    protected $model = EventSponsor::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'event_id' => Event::factory(),
            'name' => $this->faker->company(),
            'tier' => EventSponsor::TIER_SUPPORTING,
            'booth' => null,
            'amount' => 0,
            'currency' => 'GHS',
        ];
    }

    public function headline(): self
    {
        return $this->state(['tier' => EventSponsor::TIER_HEADLINE]);
    }
}
