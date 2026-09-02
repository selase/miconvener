<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enum\UsageMetric;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Tax;
use App\Models\Tenant;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

final class SampleInvoiceSeeder extends Seeder
{
    public function run(): void
    {
        $tenants = Tenant::where('status', 'active')->get();

        if ($tenants->isEmpty()) {
            $this->command->warn('No active tenants found. Run TenantSeeder first.');

            return;
        }

        foreach ($tenants as $tenant) {
            // 1. Create a PAID invoice for 2 months ago
            $this->createInvoice($tenant, Carbon::now()->subMonths(2)->startOfMonth(), Carbon::now()->subMonths(2)->endOfMonth(), Invoice::STATUS_PAID);

            // 2. Create an ISSUED invoice for last month
            $this->createInvoice($tenant, Carbon::now()->subMonth()->startOfMonth(), Carbon::now()->subMonth()->endOfMonth(), Invoice::STATUS_ISSUED);

            // 3. Create a DRAFT invoice for the "current" month (though usually generated at end of month, good for testing adjustments)
            $this->createInvoice($tenant, Carbon::now()->startOfMonth(), Carbon::now()->endOfMonth(), Invoice::STATUS_DRAFT);
        }
    }

    private function createInvoice(Tenant $tenant, Carbon $start, Carbon $end, string $status): void
    {
        $invoice = Invoice::create([
            'tenant_id' => $tenant->id,
            'number' => 'INV-'.$start->format('Ym').'-'.mb_strtoupper(Str::random(4)),
            'period_start' => $start,
            'period_end' => $end,
            'due_at' => $end->copy()->addDays(14),
            'status' => $status,
            'currency' => 'USD',
        ]);

        $subtotal = 0;

        // Base Plan
        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'description' => 'Pro Plan - Monthly Base',
            'quantity' => 1,
            'unit_price' => 49.00,
            'subtotal' => 49.00,
            'meta' => ['type' => 'base'],
        ]);
        $subtotal += 49.00;

        // Metered: Requests
        $reqQty = random_int(500000, 2000000);
        $reqCost = ($reqQty / 1000000) * 1.00;
        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'metric' => UsageMetric::REQUEST_COUNT,
            'description' => 'Metered Usage: HTTP Requests',
            'quantity' => $reqQty,
            'unit_price' => 1.00,
            'subtotal' => $reqCost,
            'meta' => ['type' => 'metered', 'unit_quantity' => 1000000],
        ]);
        $subtotal += $reqCost;

        // Metered: Storage
        $storageQty = random_int(10, 100) * 1073741824; // 10-100 GB
        $storageCost = ($storageQty / 1073741824) * 0.023;
        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'metric' => UsageMetric::STORAGE_BYTES,
            'description' => 'Metered Usage: Cloud Storage',
            'quantity' => $storageQty,
            'unit_price' => 0.023,
            'subtotal' => $storageCost,
            'meta' => ['type' => 'metered', 'unit_quantity' => 1073741824],
        ]);
        $subtotal += $storageCost;

        // Random adjustment for Drafts to test UI
        if ($status === Invoice::STATUS_DRAFT) {
            InvoiceItem::create([
                'invoice_id' => $invoice->id,
                'description' => 'One-time Setup Credit',
                'quantity' => 1,
                'unit_price' => -10.00,
                'subtotal' => -10.00,
                'meta' => ['type' => 'adjustment'],
            ]);
            $subtotal -= 10.00;
        }

        $taxCalc = Tax::calculateFor($subtotal);

        $invoice->update([
            'subtotal' => $subtotal,
            'tax_total' => $taxCalc['total_tax'],
            'total' => $subtotal + $taxCalc['total_tax'],
            'tax_details' => $taxCalc['taxes'],
        ]);
    }
}
