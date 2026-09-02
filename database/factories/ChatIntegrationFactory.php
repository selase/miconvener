<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ChatIntegration;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChatIntegration>
 */
final class ChatIntegrationFactory extends Factory
{
    protected $model = ChatIntegration::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'user_id' => User::factory(),
            'provider' => fake()->randomElement(['slack', 'teams']),
            'bot_token_encrypted' => fake()->sha256(),
            'access_token_encrypted' => fake()->sha256(),
            'is_active' => true,
            'notification_settings' => [
                'minutes_summary' => true,
                'task_assignment' => true,
                'meeting_reminder' => true,
                'task_status_update' => true,
            ],
        ];
    }

    public function slack(): static
    {
        return $this->state(fn (): array => [
            'provider' => 'slack',
            'bot_token_encrypted' => 'xoxb-'.fake()->sha256(),
            'access_token_encrypted' => 'xoxp-'.fake()->sha256(),
            'team_id' => 'T'.fake()->bothify('?#?#?#?#?#'),
            'team_name' => fake()->company().' Workspace',
        ]);
    }

    public function teams(): static
    {
        return $this->state(fn (): array => [
            'provider' => 'teams',
            'bot_token_encrypted' => null,
            'access_token_encrypted' => fake()->sha256(),
            'refresh_token_encrypted' => fake()->sha256(),
            'token_expires_at' => now()->addHour(),
            'team_id' => fake()->uuid(),
            'team_name' => fake()->company().' Team',
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }

    public function withChannel(string $channelId = 'C0123456789', string $channelName = '#general'): static
    {
        return $this->state(fn (): array => [
            'channel_id' => $channelId,
            'channel_name' => $channelName,
        ]);
    }
}
