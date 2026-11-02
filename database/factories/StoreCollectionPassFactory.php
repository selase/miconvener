<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\StoreCollectionPass;
use App\Models\StoreFulfilmentArrangement;
use App\Models\StoreFulfilmentEvent;
use App\Models\StoreFulfilmentPolicy;
use App\Models\StoreOrder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<StoreCollectionPass> */
final class StoreCollectionPassFactory extends Factory
{
    protected $model = StoreCollectionPass::class;

    /** @return array<string,mixed> */
    public function definition(): array
    {
        return ['event_id' => StoreFulfilmentEvent::factory(), 'order_id' => fn (array $a) => StoreFulfilmentEvent::findOrFail($a['event_id'])->order_id,
            'shop_id' => fn (array $a) => StoreOrder::findOrFail($a['order_id'])->shop_id,
            'tenant_id' => fn (array $a) => StoreOrder::findOrFail($a['order_id'])->tenant_id,
            'method' => function (array $a): string {
                $order = StoreOrder::findOrFail($a['order_id']);
                $arrangement = StoreFulfilmentArrangement::query()->where('order_id', $order->id)->first();
                if ($arrangement === null) {
                    $policy = StoreFulfilmentPolicy::factory()->create(['tenant_id' => $order->tenant_id, 'shop_id' => $order->shop_id]);
                    $arrangement = StoreFulfilmentArrangement::factory()->create(['order_id' => $order->id, 'shop_id' => $order->shop_id, 'tenant_id' => $order->tenant_id, 'policy_id' => $policy->id]);
                }

                return $arrangement->method;
            },
            'operation_key' => (string) Str::uuid(), 'issued_to' => 'buyer@example.test', 'qr_hash' => hash('sha256', str_repeat('a', 64)),
            'manual_hash' => hash('sha256', '7K2M4Q9R8H3V'), 'secret_material' => ['qr' => str_repeat('a', 64), 'manual' => '7K2M4Q9R8H3V'], 'expires_at' => now()->addMinutes(15)];
    }
}
