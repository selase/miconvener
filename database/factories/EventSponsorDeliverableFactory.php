<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\EventSponsor;
use App\Models\EventSponsorDeliverable;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<EventSponsorDeliverable> */
final class EventSponsorDeliverableFactory extends Factory
{
    protected $model = EventSponsorDeliverable::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'sponsor_id' => EventSponsor::factory(),
            'description' => 'Logo on the closing plenary slide',
            'is_done' => false,
        ];
    }
}
