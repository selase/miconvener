<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\BadgeTemplateLicence;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<BadgeTemplateLicence> */
final class BadgeTemplateLicenceFactory extends Factory
{
    protected $model = BadgeTemplateLicence::class;

    public function definition(): array
    {
        return [
            'event_id' => \App\Models\Event::factory(),
            'buyer_tenant_id' => fn (array $attributes) => \App\Models\Event::query()->whereKey($attributes['event_id'])->firstOrFail()->tenant_id,
            'family_id' => fn () => \App\Models\BadgeLibraryDesign::query()->create(['scope' => 'platform', 'name' => 'Premium conference', 'price_pesewas' => 5000])->id,
            'state' => 'pending',
        ];
    }
}
