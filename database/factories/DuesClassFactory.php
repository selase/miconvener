<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enum\DuesPeriodEnum;
use App\Models\DuesClass;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DuesClass> */
final class DuesClassFactory extends Factory
{
    protected $model = DuesClass::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => fake()->unique()->words(2, true),
            'amount' => 5000,
            'currency' => 'GHS',
            'period' => DuesPeriodEnum::Monthly,
            'is_active' => true,
        ];
    }
}
