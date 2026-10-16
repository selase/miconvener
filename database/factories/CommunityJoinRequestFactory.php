<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CommunityJoinRequest;
use App\Models\MembershipType;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<CommunityJoinRequest> */
final class CommunityJoinRequestFactory extends Factory
{
    protected $model = CommunityJoinRequest::class;

    public function definition(): array
    {
        return ['tenant_id' => Tenant::factory(), 'membership_type_id' => fn (array $attributes): string => MembershipType::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'channel' => 'email', 'address' => fake()->safeEmail(), 'address_hash' => hash('sha256', Str::random(32)), 'request_key' => (string) Str::uuid(),
            'submitted_name' => fake()->name(), 'status' => 'review_required', 'capability_hash' => hash('sha256', Str::random(64)), 'submitted_at' => now()];
    }
}
