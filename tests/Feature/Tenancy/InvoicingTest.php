<?php

declare(strict_types=1);

use App\Enum\UsageMetric;
use App\Models\Invoice;
use App\Models\Package;
use App\Models\Tax;
use App\Models\Tenant;
use App\Models\UsagePrice;
use App\Models\UsageRollup;
use App\Models\User;
use App\Services\Tenancy\InvoicingService;
use App\Services\Tenancy\PricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->pricingService = new PricingService();
    $this->invoicingService = new InvoicingService($this->pricingService);

    $this->package = Package::factory()->create([
        'name' => 'Scale Plan',
        'price' => 50.00,
        'billing_model' => Package::BILLING_MODEL_FLAT_RATE,
        'markup_percentage' => 0,
    ]);

    $this->tenant = Tenant::factory()->create([
        'package_id' => $this->package->id,
    ]);

    // Setup basic pricing
    UsagePrice::create([
        'target_type' => Package::class,
        'target_id' => $this->package->id,
        'metric' => UsageMetric::REQUEST_COUNT,
        'unit_price' => 0.01, // $0.01 per request
        'unit_quantity' => 1,
    ]);
});

test('it generates invoice with base plan and metered usage', function () {
    $start = now()->subMonth()->startOfMonth();
    $end = now()->subMonth()->endOfMonth();

    // 1. Seed some usage rollups
    UsageRollup::create([
        'tenant_id' => $this->tenant->id,
        'period' => 'day',
        'period_start' => $start->copy()->addDay(),
        'metric' => UsageMetric::REQUEST_COUNT,
        'value' => 100, // 100 requests
        'dimensions' => [],
        'dimensions_hash' => 'test',
    ]);

    // 2. Generate Invoice
    $invoice = $this->invoicingService->generate($this->tenant, $start, $end);

    expect($invoice)->not->toBeNull();
    expect($invoice->status)->toBe('draft');

    // Total should be:
    // Base Plan: $50.00
    // Requests: 100 * $0.01 = $1.00
    // Subtotal: $51.00
    // Tax: 0 (default)
    // Total: $51.00

    expect((float) $invoice->subtotal)->toEqual(51.0);
    expect((float) $invoice->total)->toEqual(51.0);
    expect($invoice->items)->toHaveCount(2);
});

test('it calculates per-seat billing correctly', function () {
    $package = Package::factory()->create([
        'name' => 'Per Seat Plan',
        'price' => 10.00,
        'billing_model' => Package::BILLING_MODEL_PER_SEAT,
    ]);

    $tenant = Tenant::factory()->create(['package_id' => $package->id]);

    // Add 5 users
    User::factory()->count(5)->create();
    $tenant->users()->attach(User::all()->pluck('id'));

    $start = now()->startOfMonth();
    $end = now()->endOfMonth();

    $invoice = $this->invoicingService->generate($tenant, $start, $end);

    // 5 users * $10.00 = $50.00
    expect((float) $invoice->subtotal)->toEqual(50.0);
    $item = $invoice->items()->where('meta->type', 'base_plan')->first();
    expect((float) $item->quantity)->toEqual(5.0);
});

test('it applies taxes to invoice', function () {
    Tax::create(['name' => 'VAT', 'rate' => 10.0, 'priority' => 1]);

    $start = now()->startOfMonth();
    $end = now()->endOfMonth();

    $invoice = $this->invoicingService->generate($this->tenant, $start, $end);

    // Subtotal: $50 (no usage seeded here)
    // Tax 10%: $5
    // Total: $55
    expect((float) $invoice->subtotal)->toEqual(50.0);
    expect((float) $invoice->tax_total)->toEqual(5.0);
    expect((float) $invoice->total)->toEqual(55.0);
});
