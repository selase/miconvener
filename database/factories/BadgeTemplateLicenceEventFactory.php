<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\BadgeTemplateLicenceEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<BadgeTemplateLicenceEvent> */
final class BadgeTemplateLicenceEventFactory extends Factory
{
    protected $model = BadgeTemplateLicenceEvent::class;

    public function definition(): array
    {
        return [
            'purchase_id' => \App\Models\BadgeTemplatePurchase::factory(),
            'licence_id' => fn (array $attributes) => \App\Models\BadgeTemplatePurchase::query()->whereKey($attributes['purchase_id'])->firstOrFail()->licence_id,
            'actor_id' => \App\Models\User::factory(),
            'action' => 'requested',
            'operation_key' => (string) \Illuminate\Support\Str::uuid(),
            'evidence' => [],
        ];
    }
}
