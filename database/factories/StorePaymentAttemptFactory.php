<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\StoreOrder;
use App\Models\StorePaymentAttempt;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<StorePaymentAttempt> */
final class StorePaymentAttemptFactory extends Factory
{
    protected $model = StorePaymentAttempt::class;

    public function definition(): array
    {
        return ['order_id' => StoreOrder::factory(),
            'shop_id' => fn (array $a) => StoreOrder::findOrFail($a['order_id'])->shop_id,
            'tenant_id' => fn (array $a) => StoreOrder::findOrFail($a['order_id'])->tenant_id,
            'reference' => 'store-'.Str::uuid(), 'submission_key' => (string) Str::uuid(),
            'provider' => 'paystack', 'settlement_mode' => 'platform_default',
            'account_fingerprint' => fn (array $a) => StoreOrder::findOrFail($a['order_id'])->account_fingerprint,
            'amount_pesewas' => fn (array $a) => StoreOrder::findOrFail($a['order_id'])->charged_pesewas,
            'currency' => 'GHS', 'status' => 'initializing'];
    }
}
