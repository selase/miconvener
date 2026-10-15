<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CommunitySettings;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CommunitySettings> */
final class CommunitySettingsFactory extends Factory
{
    protected $model = CommunitySettings::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'reminders_enabled' => true,
            'reminder_offsets' => ['before' => 7, 'on_day' => 0, 'after_7' => 7, 'after_30' => 30],
            'frequent_digest' => 'daily',
            'sms_monthly_limit' => null,
            'timezone' => 'Africa/Accra',
            'week_starts_on' => 1,
            'financial_year_start_month' => 1,
            'generate_days_ahead' => 7,
            'charge_current_period_on_join' => false,
        ];
    }
}
