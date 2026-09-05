<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use App\Models\TenantPayoutAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TenantPayoutAccount> */
final class TenantPayoutAccountFactory extends Factory
{
    protected $model = TenantPayoutAccount::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'type' => TenantPayoutAccount::TYPE_BANK,
            'label' => 'Absa Bank Ghana — current',
            'account_name' => $this->faker->company(),
            'account_number_encrypted' => $this->faker->numerify('##########4417'),
            'is_verified' => true,
        ];
    }
}
