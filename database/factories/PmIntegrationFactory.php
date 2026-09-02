<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\PmIntegration;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PmIntegration>
 */
final class PmIntegrationFactory extends Factory
{
    protected $model = PmIntegration::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'user_id' => User::factory(),
            'provider' => fake()->randomElement(['jira', 'asana', 'linear', 'monday']),
            'auth_type' => 'api_key',
            'api_key_encrypted' => fake()->sha256(),
            'is_active' => true,
            'auto_push' => true,
        ];
    }

    public function jira(): static
    {
        return $this->state(fn (): array => [
            'provider' => 'jira',
            'auth_type' => 'oauth',
            'access_token_encrypted' => fake()->sha256(),
            'refresh_token_encrypted' => fake()->sha256(),
            'token_expires_at' => now()->addHour(),
            'api_key_encrypted' => null,
        ]);
    }

    public function asana(): static
    {
        return $this->state(fn (): array => [
            'provider' => 'asana',
            'auth_type' => 'api_key',
            'api_key_encrypted' => fake()->sha256(),
            'access_token_encrypted' => null,
            'refresh_token_encrypted' => null,
        ]);
    }

    public function linear(): static
    {
        return $this->state(fn (): array => [
            'provider' => 'linear',
            'auth_type' => 'api_key',
            'api_key_encrypted' => fake()->sha256(),
            'access_token_encrypted' => null,
            'refresh_token_encrypted' => null,
        ]);
    }

    public function monday(): static
    {
        return $this->state(fn (): array => [
            'provider' => 'monday',
            'auth_type' => 'oauth',
            'access_token_encrypted' => fake()->sha256(),
            'refresh_token_encrypted' => fake()->sha256(),
            'token_expires_at' => now()->addHour(),
            'api_key_encrypted' => null,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }

    public function expired(): static
    {
        return $this->state(fn (): array => ['token_expires_at' => now()->subHour()]);
    }

    public function withProject(string $projectId = 'PROJ-1', string $projectName = 'Test Project'): static
    {
        return $this->state(fn (): array => [
            'project_id' => $projectId,
            'project_name' => $projectName,
        ]);
    }
}
