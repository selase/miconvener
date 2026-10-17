<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CommunityInboxItem;
use App\Models\Member;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<CommunityInboxItem> */
final class CommunityInboxItemFactory extends Factory
{
    protected $model = CommunityInboxItem::class;

    public function definition(): array
    {
        return ['tenant_id' => Tenant::factory(), 'member_id' => fn (array $a) => Member::factory()->create(['tenant_id' => $a['tenant_id']])->id, 'recipient_type' => 'member', 'kind' => 'dues_reminder', 'source_type' => 'dues_reminder_day', 'source_id' => (string) Str::uuid(), 'subject' => 'Dues reminder', 'body' => 'Your open charges are available in Money.', 'available_at' => now()];
    }
}
