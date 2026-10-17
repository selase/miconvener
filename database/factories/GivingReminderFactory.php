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
        ];
    }
}
