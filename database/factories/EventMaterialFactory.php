<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Event;
use App\Models\EventMaterial;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<EventMaterial> */
final class EventMaterialFactory extends Factory
{
    protected $model = EventMaterial::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'event_id' => Event::factory(),
            'title' => $this->faker->sentence(3).'.pdf',
            'file_path' => 'event-materials/'.$this->faker->uuid().'.pdf',
            'file_size' => $this->faker->numberBetween(100_000, 5_000_000),
            'mime_type' => 'application/pdf',
            'download_limit' => 3,
            'release_at' => null,
        ];
    }

    public function releasedLater(): static
    {
        return $this->state(['release_at' => now()->addWeek()]);
    }
}
