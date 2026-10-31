<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CommunityFollower;
use App\Models\CommunityFollowerChannel;
use App\Services\Community\MessagingPreferences;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CommunityFollowerChannel> */
final class CommunityFollowerChannelFactory extends Factory
{
    protected $model = CommunityFollowerChannel::class;

    public function definition(): array
    {
        return ['follower_id' => CommunityFollower::factory(), 'tenant_id' => fn (array $a) => CommunityFollower::withoutGlobalScopes()->findOrFail($a['follower_id'])->tenant_id, 'channel' => 'sms', 'address' => '+233241234567', 'address_hash' => app(MessagingPreferences::class)->hash('sms', '+233241234567'), 'consented_at' => now()];
    }
}
