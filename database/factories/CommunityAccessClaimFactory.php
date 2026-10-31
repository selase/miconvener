<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CommunityAccessClaim;
use App\Models\Tenant;
use App\Services\Community\PhoneVerification;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CommunityAccessClaim> */
final class CommunityAccessClaimFactory extends Factory
{
    protected $model = CommunityAccessClaim::class;

    public function definition(): array
    {
        return ['tenant_id' => Tenant::factory(), 'phone' => '+233241234567', 'phone_hash' => PhoneVerification::digest('+233241234567'), 'claimant_name' => fake()->name(), 'role' => 'self', 'capability_hash' => hash('sha256', 'fixture-capability'), 'status' => 'pending', 'expires_at' => now()->addDays(7)];
    }
}
