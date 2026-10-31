<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\StoreFulfilmentEvent;
use App\Models\StoreOrder;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<StoreFulfilmentEvent> */
final class StoreFulfilmentEventFactory extends Factory
{
    protected $model = StoreFulfilmentEvent::class;

    /** @return array<string,mixed> */
    public function definition(): array
    {
        return ['order_id' => StoreOrder::factory(), 'shop_id' => fn (array $a) => StoreOrder::findOrFail($a['order_id'])->shop_id,
            'tenant_id' => fn (array $a) => StoreOrder::findOrFail($a['order_id'])->tenant_id, 'kind' => 'ready', 'operation_key' => (string) Str::uuid(),
            'actor_id' => User::factory(), 'actor_name' => 'Test fulfilment staff', 'reason' => null, 'lines_snapshot' => [], 'terms_hash' => hash('sha256', 'fixture')];
    }
}
