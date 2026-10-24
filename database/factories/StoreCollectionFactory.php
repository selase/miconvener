<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\StoreCollection;
use App\Models\StoreCollectionPass;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<StoreCollection> */
final class StoreCollectionFactory extends Factory
{
    protected $model = StoreCollection::class;

    /** @return array<string,mixed> */
    public function definition(): array
    {
        return ['pass_id' => StoreCollectionPass::factory(), 'order_id' => fn (array $a) => StoreCollectionPass::findOrFail($a['pass_id'])->order_id,
            'tenant_id' => fn (array $a) => StoreCollectionPass::findOrFail($a['pass_id'])->tenant_id, 'shop_id' => fn (array $a) => StoreCollectionPass::findOrFail($a['pass_id'])->shop_id,
            'method' => fn (array $a) => StoreCollectionPass::findOrFail($a['pass_id'])->method, 'operation_key' => (string) Str::uuid(), 'actor_id' => User::factory(), 'actor_name' => 'Test fulfilment staff',
            'source' => 'manual', 'lines_snapshot' => [], 'handed_over_at' => now()];
    }
}
