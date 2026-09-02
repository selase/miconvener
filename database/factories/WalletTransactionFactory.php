<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enum\WalletTransactionType;
use App\Models\Tenant;
use App\Models\WalletTransaction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WalletTransaction>
 */
final class WalletTransactionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'type' => WalletTransactionType::Deposit,
            'amount' => fake()->numberBetween(100, 10000),
            'balance_after' => fake()->numberBetween(100, 50000),
            'currency' => 'USD',
            'description' => fake()->sentence(),
            'meta' => null,
        ];
    }

    public function deposit(): static
    {
        return $this->state(fn (): array => [
            'type' => WalletTransactionType::Deposit,
            'reference_type' => 'topup',
        ]);
    }

    public function deduction(): static
    {
        return $this->state(fn (): array => [
            'type' => WalletTransactionType::Deduction,
            'reference_type' => 'meeting',
        ]);
    }

    public function refund(): static
    {
        return $this->state(fn (): array => [
            'type' => WalletTransactionType::Refund,
            'reference_type' => 'refund',
        ]);
    }
}
