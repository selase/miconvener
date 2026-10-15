<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enum\DuesPeriodEnum;
use App\Models\MembershipType;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MembershipType> */
final class MembershipTypeFactory extends Factory
{
    protected $model = MembershipType::class;

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
