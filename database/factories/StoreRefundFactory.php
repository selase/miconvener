<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\StoreOrder;
use App\Models\StoreRefund;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<StoreRefund> */
final class StoreRefundFactory extends Factory
{
    protected $model = StoreRefund::class;

    public function definition(): array
    {
        return ['order_id' => StoreOrder::factory()->state(['principal_pesewas' => 0, 'charged_pesewas' => 0, 'platform_fee_pesewas' => 0, 'gateway_estimate_pesewas' => 0]),
            'tenant_id' => fn (array $a) => StoreOrder::findOrFail($a['order_id'])->tenant_id,
            'shop_id' => fn (array $a) => StoreOrder::findOrFail($a['order_id'])->shop_id,
            'account_fingerprint' => fn (array $a) => StoreOrder::findOrFail($a['order_id'])->account_fingerprint,
            'operation_key' => (string) Str::uuid(), 'reason' => 'Uncollected cancellation', 'requested_by' => User::factory(),
            'amount_pesewas' => 0, 'seller_debit_pesewas' => 0, 'commission_return_pesewas' => 0, 'gateway_fee_pesewas' => 0, 'review_liability_pesewas' => 0,
            'fee_bearer' => 'seller', 'restock' => true, 'status' => 'confirmed', 'provider_status' => 'not_required'];
    }
}
