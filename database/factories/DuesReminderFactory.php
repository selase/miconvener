<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\DuesReminder;
use App\Models\Member;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DuesReminder> */
final class DuesReminderFactory extends Factory
{
    protected $model = DuesReminder::class;

    public function definition(): array
    {
        return [
            'member_id' => Member::factory(),
            'tenant_id' => fn (array $attributes) => Member::withoutGlobalScopes()->findOrFail($attributes['member_id'])->tenant_id,
            'step' => 'manual', 'channel' => 'sms', 'charge_ids' => [], 'status' => 'reserved', 'sent_for_date' => now()->toDateString(),
        ];
    }
}
