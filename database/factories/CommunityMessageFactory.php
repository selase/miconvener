<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CommunityMessage;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<CommunityMessage> */
final class CommunityMessageFactory extends Factory
{
    protected $model = CommunityMessage::class;

    public function definition(): array
    {
        return ['tenant_id' => Tenant::factory(), 'subject' => 'Community update', 'body' => 'Next gathering', 'channels' => ['email'], 'audience' => ['kind' => 'all_members'], 'estimate' => ['email' => 0], 'status' => 'draft', 'idempotency_key' => Str::uuid()->toString(), 'payload_hash' => hash('sha256', 'factory')];
    }
}
