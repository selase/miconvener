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
            'timezone' => 'Africa/Accra',
            'week_starts_on' => 1,
            'financial_year_start_month' => 1,
            'generate_days_ahead' => 7,
            'charge_current_period_on_join' => false,
        ];
    }
}
