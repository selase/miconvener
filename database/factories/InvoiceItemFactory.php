<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enum\UsageMetric;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<InvoiceItem> */
final class InvoiceItemFactory extends Factory
{
    protected $model = InvoiceItem::class;

    public function definition(): array
    {
        $quantity = $this->faker->randomFloat(4, 1, 1000);
        $unitPrice = $this->faker->randomFloat(6, 0.01, 10);

        return [
            'invoice_id' => Invoice::factory(),
            'metric' => $this->faker->randomElement(UsageMetric::cases()),
            'description' => $this->faker->sentence(3),
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'subtotal' => round($quantity * $unitPrice, 4),
            'meta' => [],
        ];
    }
}
