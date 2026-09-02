<?php

declare(strict_types=1);

use App\Enum\UsageMetric;
use App\Models\Package;
use App\Models\Tax;
use App\Models\Tenant;
use App\Models\UsagePrice;
use App\Services\Tenancy\PricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->pricingService = new PricingService();

    $this->package = Package::factory()->create([
        'name' => 'Scale Plan',
        'markup_percentage' => 10.00, // 10% plan markup
    ]);

    $this->tenant = Tenant::factory()->create([
        'package_id' => $this->package->id,
        'markup_percentage' => 5.00, // 5% tenant override markup
    ]);

    // Total markup should be 15% (assuming 0 global)
});

test('it resolves unit price from package', function () {
    UsagePrice::create([
        'target_type' => Package::class,
        'target_id' => $this->package->id,
        'metric' => UsageMetric::REQUEST_COUNT,
        'unit_price' => 0.10,
        'unit_quantity' => 1000,
    ]);

    $price = $this->pricingService->getUnitPrice($this->tenant, UsageMetric::REQUEST_COUNT);

    expect($price)->not->toBeNull();
    expect((float) $price->unit_price)->toEqual(0.10);
});

test('it resolves unit price from tenant (override)', function () {
    // Package price
    UsagePrice::create([
        'target_type' => Package::class,
        'target_id' => $this->package->id,
        'metric' => UsageMetric::REQUEST_COUNT,
        'unit_price' => 0.10,
        'unit_quantity' => 1000,
    ]);

    // Tenant override
    UsagePrice::create([
        'target_type' => Tenant::class,
        'target_id' => $this->tenant->id,
        'metric' => UsageMetric::REQUEST_COUNT,
        'unit_price' => 0.08,
        'unit_quantity' => 1000,
    ]);

    $price = $this->pricingService->getUnitPrice($this->tenant, UsageMetric::REQUEST_COUNT);

    expect((float) $price->unit_price)->toEqual(0.08);
});

test('it calculates markups correctly', function () {
    config(['billing.global_markup' => 2.0]); // 2% global

    $markup = $this->pricingService->getEffectiveMarkup($this->tenant);

    // 2 (global) + 10 (package) + 5 (tenant) = 17%
    expect($markup)->toEqual(17.0);
});

test('it calculates cost with markups', function () {
    UsagePrice::create([
        'target_type' => Package::class,
        'target_id' => $this->package->id,
        'metric' => UsageMetric::REQUEST_COUNT,
        'unit_price' => 1.00,
        'unit_quantity' => 1,
    ]);

    // 100 units * $1.00 = $100.
    // Markups: 10% (package) + 5% (tenant) = 15%.
    // Total: $115.

    $cost = $this->pricingService->calculateCost($this->tenant, UsageMetric::REQUEST_COUNT, 100);

    expect($cost)->toEqual(115.0);
});

test('it calculates taxes correctly', function () {
    Tax::create(['name' => 'NHIL', 'rate' => 2.5, 'priority' => 1]);
    Tax::create(['name' => 'VAT', 'rate' => 15.0, 'priority' => 2]);

    $calculation = Tax::calculateFor(100.0);

    expect($calculation['total_tax'])->toEqual(17.5);
    expect($calculation['taxes'])->toHaveCount(2);
});

test('it handles compound taxes', function () {
    Tax::create(['name' => 'Tax A', 'rate' => 10.0, 'priority' => 1]);
    Tax::create(['name' => 'Tax B', 'rate' => 10.0, 'priority' => 2, 'is_compound' => true]);

    // Tax A = 10% of 100 = 10.
    // Tax B = 10% of (100 + 10) = 11.
    // Total Tax = 10 + 11 = 21.

    $calculation = Tax::calculateFor(100.0);

    expect($calculation['total_tax'])->toEqual(21.0);
});
