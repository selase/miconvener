<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CommunityAccessCredential;
use App\Models\Member;
use App\Models\Tenant;
use App\Services\Community\PhoneVerification;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/** @extends Factory<CommunityAccessCredential> */
final class CommunityAccessCredentialFactory extends Factory
{
    protected $model = CommunityAccessCredential::class;

    public function definition(): array
    {
        return ['tenant_id' => Tenant::factory(), 'member_id' => fn (array $attributes) => Member::factory()->create(['tenant_id' => $attributes['tenant_id'], 'phone' => '+233241234567'])->id, 'phone' => '+233241234567', 'phone_hash' => PhoneVerification::digest('+233241234567'), 'role' => 'self', 'secret_hash' => Hash::make('fixture-secret'), 'version' => 1, 'member_access_version' => 1, 'approved_at' => now(), 'method' => 'in_person'];
    }
}
