<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<\App\Models\StoreFulfilmentPolicy> */
final class StoreFulfilmentPolicyFactory extends Factory
{
    protected $model = \App\Models\StoreFulfilmentPolicy::class;

    /** @return array<string,mixed> */
    public function definition(): array
    {
        return ['shop_id' => \App\Models\Shop::factory(), 'tenant_id' => fn (array $a) => \App\Models\Shop::findOrFail($a['shop_id'])->tenant_id,
            'version' => 1, 'operation_key' => (string) \Illuminate\Support\Str::uuid(), 'fingerprint' => hash('sha256', 'fixture'),
            'snapshot' => ['methods' => ['collection'], 'collection_location' => 'Association office']];
    }
}
