<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\GivingFund;
use App\Models\GivingReminder;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<GivingReminder> */
final class GivingReminderFactory extends Factory
{
    protected $model = GivingReminder::class;

    public function definition(): array
    {
        return [
            'fund_id' => GivingFund::factory(),
            'tenant_id' => fn (array $attributes) => GivingFund::withoutGlobalScopes()->findOrFail($attributes['fund_id'])->tenant_id,
            'consent_key' => (string) \Illuminate\Support\Str::uuid(), 'email' => 'giver@example.test', 'email_hash' => hash('sha256', \Illuminate\Support\Str::random()),
            'consented_at' => now(), 'cadence' => 'monthly', 'anchor_day' => 1, 'local_time' => '09:00', 'timezone' => 'Africa/Accra',
            'next_due_at' => now()->addMonth()->startOfMonth()->setTime(9, 0), 'unsubscribe_hash' => hash('sha256', \Illuminate\Support\Str::random()),
        ];
    }
}
