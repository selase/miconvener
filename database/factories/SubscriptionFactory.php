<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Subscription;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

final class SubscriptionFactory extends Factory
{
    protected $model = Subscription::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => 'default',
            'provider_id' => 'sub_'.$this->faker->uuid,
            'provider_status' => 'active',
            'provider_plan' => 'price_'.$this->faker->word,
            'quantity' => 1,
            'trial_ends_at' => null,
            'ends_at' => null,
            'current_period_end' => now()->addMonth(),
        ];
    }
}
