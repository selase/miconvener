<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use App\Models\TenantFollowUp;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TenantFollowUp> */
final class TenantFollowUpFactory extends Factory
{
    protected $model = TenantFollowUp::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'user_id' => null,
            'status' => TenantFollowUp::STATUS_CONTACTED,
            'note' => $this->faker->sentence(),
        ];
    }
}
