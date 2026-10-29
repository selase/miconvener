<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\StoreOrder;
use App\Models\StoreSellerQuote;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<StoreSellerQuote> */
final class StoreSellerQuoteFactory extends Factory
{
    protected $model = StoreSellerQuote::class;

    /** @return array<string,mixed> */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'snapshot' => fn (array $attributes): array => ['quote_id' => $attributes['id'], 'goods_pesewas' => 10000, 'printing_pesewas' => 0, 'delivery_pesewas' => 0, 'production_terms' => 'Seven working days after payment.', 'customisation' => 'No custom printing.'],
            'order_id' => fn (array $attributes): string => StoreOrder::factory()->create(['terms_snapshot' => ['seller_quote' => $attributes['snapshot']]])->id,
            'shop_id' => fn (array $attributes): string => StoreOrder::query()->findOrFail($attributes['order_id'])->shop_id,
            'tenant_id' => fn (array $attributes): string => StoreOrder::query()->findOrFail($attributes['order_id'])->tenant_id,
            'issued_by' => fn (array $attributes): string => User::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'issuer_name' => 'Authorised seller', 'operation_key' => (string) Str::uuid(), 'request_hash' => hash('sha256', (string) Str::uuid()),
        ];
    }
}
