<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\GivingFund;
use App\Models\GivingGift;
use App\Models\GivingPaymentAttempt;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<GivingPaymentAttempt> */
final class GivingPaymentAttemptFactory extends Factory
{
    protected $model = GivingPaymentAttempt::class;

    public function definition(): array
    {
        return [
            'tenant_id' => fn (array $attributes) => GivingFund::withoutGlobalScopes()->findOrFail($attributes['fund_id'])->tenant_id,
            'fund_id' => GivingFund::factory(),
            'gift_id' => GivingGift::factory(),
        ];
    }
}
