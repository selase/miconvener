<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Package;
use App\Models\PlanChange;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PlanChange> */
final class PlanChangeFactory extends Factory
{
    protected $model = PlanChange::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'from_package_id' => Package::factory(),
            'to_package_id' => Package::factory(),
            'status' => 'pending',
            'effective_at' => now()->addDays(30),
            'processed_at' => null,
            'meta' => [],
        ];
    }
}
