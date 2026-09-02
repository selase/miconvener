<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\MerchantTransaction;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MerchantTransaction> */
final class MerchantTransactionFactory extends Factory
{
    protected $model = MerchantTransaction::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'provider' => $this->faker->randomElement(['stripe', 'paystack']),
            'provider_transaction_id' => 'ch_'.$this->faker->uuid(),
            'amount' => $this->faker->numberBetween(500, 500000),
            'currency' => 'USD',
            'status' => 'succeeded',
            'type' => 'payment',
            'description' => $this->faker->sentence(3),
            'customer_email' => $this->faker->safeEmail(),
            'customer_name' => $this->faker->name(),
            'meta' => [],
        ];
    }

    public function refund(): static
    {
        return $this->state([
            'type' => 'refund',
            'description' => 'Refund',
        ]);
    }
}
