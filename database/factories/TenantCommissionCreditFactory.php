<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use App\Models\TenantCommissionCredit;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TenantCommissionCredit> */
final class TenantCommissionCreditFactory extends Factory
{
    protected $model = TenantCommissionCredit::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'currency' => 'GHS',
            'amount' => 0,
        ];
    }
}
