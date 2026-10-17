<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\GivingFund;
use App\Models\GivingGift;
use App\Models\GivingReceiptNotice;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<GivingReceiptNotice> */
final class GivingReceiptNoticeFactory extends Factory
{
    protected $model = GivingReceiptNotice::class;

    public function definition(): array
    {
        return [
            'fund_id' => GivingFund::factory(),
            'tenant_id' => fn (array $attributes) => GivingFund::withoutGlobalScopes()->findOrFail($attributes['fund_id'])->tenant_id,
            'gift_id' => fn (array $attributes) => GivingGift::factory()->create(['fund_id' => $attributes['fund_id'], 'tenant_id' => $attributes['tenant_id']])->id,
            'payment_key' => 'gift:'.\Illuminate\Support\Str::uuid(), 'receipt_number' => 'GIVING-'.\Illuminate\Support\Str::uuid(),
            'snapshot' => ['amount' => 10000, 'currency' => 'GHS'], 'destination' => 'giver@example.test', 'state' => 'queued',
        ];
    }
}
