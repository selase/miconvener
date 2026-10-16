<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CommunityContactNotice;
use App\Models\Member;
use App\Models\Tenant;
use App\Services\Community\MessagingPreferences;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CommunityContactNotice> */
final class CommunityContactNoticeFactory extends Factory
{
    protected $model = CommunityContactNotice::class;

    public function definition(): array
    {
        return ['tenant_id' => Tenant::factory(), 'member_id' => fn (array $attributes) => Member::factory()->create(['tenant_id' => $attributes['tenant_id']])->id, 'contact_version' => 1, 'channel' => 'email', 'address' => 'notice@example.test', 'address_hash' => app(MessagingPreferences::class)->hash('email', 'notice@example.test'), 'status' => 'pending'];
    }
}
