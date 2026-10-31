<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CommunityMessage;
use App\Models\CommunityMessageDelivery;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CommunityMessageDelivery> */
final class CommunityMessageDeliveryFactory extends Factory
{
    protected $model = CommunityMessageDelivery::class;

    public function definition(): array
    {
        return ['message_id' => CommunityMessage::factory(), 'tenant_id' => fn (array $attributes) => CommunityMessage::withoutGlobalScopes()->findOrFail($attributes['message_id'])->tenant_id, 'channel' => 'email', 'address' => 'ama@example.test', 'address_hash' => hash('sha256', 'factory-'.fake()->uuid()), 'status' => 'queued'];
    }
}
