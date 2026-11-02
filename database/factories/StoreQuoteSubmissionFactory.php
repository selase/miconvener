<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\StoreQuoteSubmission>
 */
final class StoreQuoteSubmissionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['order_id' => \App\Models\StoreOrder::factory(), 'tenant_id' => fn (array $a) => \App\Models\StoreOrder::findOrFail($a['order_id'])->tenant_id,
            'shop_id' => fn (array $a) => \App\Models\StoreOrder::findOrFail($a['order_id'])->shop_id,
            'buyer_email' => fn (array $a) => \App\Models\StoreOrder::findOrFail($a['order_id'])->buyer_email,
            'submission_key' => (string) \Illuminate\Support\Str::uuid(), 'cart_hash' => fn (array $a) => \App\Models\StoreOrder::findOrFail($a['order_id'])->cart_hash];
    }
}
