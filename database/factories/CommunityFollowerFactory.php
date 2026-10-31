<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CommunityFollower;
use App\Models\Tenant;
use App\Services\Community\MessagingPreferences;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CommunityFollower> */
final class CommunityFollowerFactory extends Factory
{
    protected $model = CommunityFollower::class;

    public function definition(): array
    {
        $address = fake()->unique()->safeEmail();

        return ['tenant_id' => Tenant::factory(), 'channel' => 'email', 'address' => $address,
            'address_hash' => fn (array $attributes) => app(MessagingPreferences::class)->hash($attributes['channel'], $attributes['address']),
            'consented_at' => now(), 'consent_source' => 'organisation'];
    }
}
