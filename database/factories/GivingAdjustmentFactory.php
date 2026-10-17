<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\GivingAdjustment;
use App\Models\GivingFund;
use App\Models\GivingGift;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<GivingAdjustment> */
final class GivingAdjustmentFactory extends Factory
{
    protected $model = GivingAdjustment::class;

    public function definition(): array
    {
        return [
            'fund_id' => GivingFund::factory(),
            'tenant_id' => fn (array $attributes) => GivingFund::withoutGlobalScopes()->findOrFail($attributes['fund_id'])->tenant_id,
            'gift_id' => fn (array $attributes) => GivingGift::factory()->create(['fund_id' => $attributes['fund_id'], 'tenant_id' => $attributes['tenant_id']])->id,
            'reason' => 'Synthetic adjustment', 'principal' => 1000, 'kind' => 'refund', 'status' => 'requested',
            'operation_key' => (string) \Illuminate\Support\Str::uuid(),
        ];
    }
}
