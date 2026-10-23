<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\StoreCommercialAgreement;
use App\Models\StoreOrder;
use App\Models\StoreOrderLine;
use App\Models\StoreVariant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<StoreOrderLine> */
final class StoreOrderLineFactory extends Factory
{
    protected $model = StoreOrderLine::class;

    public function definition(): array
    {
        return [
            'variant_id' => StoreVariant::factory(),
            'order_id' => function (array $a): string {
                $variant = StoreVariant::findOrFail($a['variant_id']);
                $agreement = StoreCommercialAgreement::factory()->create(['shop_id' => $variant->shop_id, 'tenant_id' => $variant->shop->tenant_id]);

                return StoreOrder::factory()->create(['agreement_id' => $agreement->id])->id;
            },
            'shop_id' => fn (array $a) => StoreVariant::findOrFail($a['variant_id'])->shop_id,
            'listing_id' => fn (array $a) => StoreVariant::findOrFail($a['variant_id'])->listing_id,
            'title_snapshot' => 'Association shirt', 'sku_snapshot' => 'SHIRT-M', 'options_snapshot' => ['Size' => 'M'],
            'quantity' => 1, 'unit_price_pesewas' => 3000, 'line_amount_pesewas' => 3000,
        ];
    }
}
