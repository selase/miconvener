<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Factories\Factory;

final class TransactionFactory extends Factory
{
    protected $model = Transaction::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'provider' => 'stripe',
            'provider_transaction_id' => 'tx_'.$this->faker->uuid,
            'amount' => 1000,
            'currency' => 'USD',
            'status' => 'success',
            'type' => 'charge',
            'meta' => ['source' => 'test'],
        ];
    }
}
