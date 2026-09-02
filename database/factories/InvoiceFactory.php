<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Invoice;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Invoice> */
final class InvoiceFactory extends Factory
{
    protected $model = Invoice::class;

    public function definition(): array
    {
        $subtotal = $this->faker->randomFloat(4, 10, 5000);
        $taxTotal = round($subtotal * 0.1, 4);

        return [
            'tenant_id' => Tenant::factory(),
            'number' => 'INV-'.$this->faker->unique()->numerify('######'),
            'period_start' => now()->subMonth()->startOfMonth(),
            'period_end' => now()->subMonth()->endOfMonth(),
            'due_at' => now()->addDays(30),
            'status' => 'draft',
            'currency' => 'USD',
            'subtotal' => $subtotal,
            'tax_total' => $taxTotal,
            'total' => $subtotal + $taxTotal,
            'tax_details' => [],
            'meta' => [],
        ];
    }

    public function issued(): static
    {
        return $this->state(['status' => 'issued']);
    }

    public function paid(): static
    {
        return $this->state(['status' => 'paid']);
    }

    public function overdue(): static
    {
        return $this->state([
            'status' => 'overdue',
            'due_at' => now()->subDays(7),
        ]);
    }
}
