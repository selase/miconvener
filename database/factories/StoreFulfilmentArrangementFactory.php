<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<\App\Models\StoreFulfilmentArrangement> */
final class StoreFulfilmentArrangementFactory extends Factory
{
    protected $model = \App\Models\StoreFulfilmentArrangement::class;

    /** @return array<string,mixed> */
    public function definition(): array
    {
        return ['policy_id' => \App\Models\StoreFulfilmentPolicy::factory(),
            'order_id' => fn (array $a) => \App\Models\StoreOrder::factory()->create(['shop_id' => \App\Models\StoreFulfilmentPolicy::findOrFail($a['policy_id'])->shop_id, 'tenant_id' => \App\Models\StoreFulfilmentPolicy::findOrFail($a['policy_id'])->tenant_id,
                'agreement_id' => \App\Models\StoreCommercialAgreement::factory()->create(['shop_id' => \App\Models\StoreFulfilmentPolicy::findOrFail($a['policy_id'])->shop_id, 'tenant_id' => \App\Models\StoreFulfilmentPolicy::findOrFail($a['policy_id'])->tenant_id])->id])->id,
            'shop_id' => fn (array $a) => \App\Models\StoreOrder::findOrFail($a['order_id'])->shop_id, 'tenant_id' => fn (array $a) => \App\Models\StoreOrder::findOrFail($a['order_id'])->tenant_id,
            'method' => 'collection', 'operation_key' => (string) \Illuminate\Support\Str::uuid(), 'fingerprint' => hash('sha256', 'fixture'),
            'policy_snapshot' => ['methods' => ['collection'], 'collection_location' => 'Association office'], 'destination' => null];
    }
}
