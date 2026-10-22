<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\StoreStockMovement;
use App\Models\StoreVariant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<StoreStockMovement> */
final class StoreStockMovementFactory extends Factory
{
    protected $model = StoreStockMovement::class;

    public function definition(): array
    {
        return [
            'variant_id' => StoreVariant::factory(),
            'shop_id' => fn (array $attributes) => StoreVariant::query()->whereKey($attributes['variant_id'])->firstOrFail()->shop_id,
            'idempotency_key' => (string) Str::uuid(), 'delta' => 1, 'balance_after' => 1,
            'reason' => 'Test stock receipt', 'actor_id' => User::factory(),
        ];
    }
}
