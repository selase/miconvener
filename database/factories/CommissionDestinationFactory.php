<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CommissionDestination;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CommissionDestination> */
final class CommissionDestinationFactory extends Factory
{
    protected $model = CommissionDestination::class;

    public function definition(): array
    {
        return [
            'label' => 'MTN MoMo',
            'currency' => 'GHS',
            'business_name' => 'MiConvener',
            'settlement_bank' => 'MTN',
            'account_number' => '0551234987',
            'is_active' => true,
            'accepted_until' => null,
        ];
    }
}
