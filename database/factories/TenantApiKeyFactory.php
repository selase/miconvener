<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use App\Models\TenantApiKey;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<TenantApiKey> */
final class TenantApiKeyFactory extends Factory
{
    protected $model = TenantApiKey::class;

    public function definition(): array
    {
        $key = Str::random(64);

        return [
            'tenant_id' => Tenant::factory(),
            'user_id' => User::factory(),
            'name' => $this->faker->words(2, true).' API Key',
            'key_hash' => hash('sha256', $key),
            'key_prefix' => mb_substr($key, 0, 8),
            'scopes' => ['read', 'write'],
            'ip_restrictions' => [],
            'expires_at' => now()->addYear(),
            'revoked_at' => null,
            'last_used_at' => null,
        ];
    }

    public function expired(): static
    {
        return $this->state(['expires_at' => now()->subDay()]);
    }

    public function revoked(): static
    {
        return $this->state(['revoked_at' => now()]);
    }
}
