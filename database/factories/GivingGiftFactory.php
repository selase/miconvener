<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\GivingFund;
use App\Models\GivingGift;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<GivingGift> */
final class GivingGiftFactory extends Factory
{
    protected $model = GivingGift::class;

    public function definition(): array
    {
        return [
            'fund_id' => GivingFund::factory(),
            'tenant_id' => fn (array $attributes) => GivingFund::withoutGlobalScopes()->findOrFail($attributes['fund_id'])->tenant_id,
            'amount' => 10000,
            'currency' => 'GHS',
            'source' => 'online',
            'status' => 'pending',
            'submission_key' => (string) \Illuminate\Support\Str::uuid(),
        ];
    }
}
