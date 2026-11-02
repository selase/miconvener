<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\StoreOrderLine;
use App\Models\StoreStockAllocation;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<StoreStockAllocation> */
final class StoreStockAllocationFactory extends Factory
{
    protected $model = StoreStockAllocation::class;

    public function definition(): array
    {
        return ['line_id' => StoreOrderLine::factory(),
            'order_id' => fn (array $a) => StoreOrderLine::findOrFail($a['line_id'])->order_id,
            'shop_id' => fn (array $a) => StoreOrderLine::findOrFail($a['line_id'])->shop_id,
            'variant_id' => fn (array $a) => StoreOrderLine::findOrFail($a['line_id'])->variant_id,
            'quantity' => fn (array $a) => StoreOrderLine::findOrFail($a['line_id'])->quantity,
            'expires_at' => now()->addMinutes(10), 'state' => 'active'];
    }
}
