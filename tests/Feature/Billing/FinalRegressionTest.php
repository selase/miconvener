<?php

declare(strict_types=1);

use App\Enum\UsageMetric;
use App\Models\Package;
use App\Models\Tenant;
use App\Models\UsageLimit;
use App\Models\UsageRollup;
use App\Services\Tenancy\InvoicingService;
use App\Services\Tenancy\PricingService;
use App\Services\Tenancy\UsageLimitService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('end-to-end billing cycle', function () {
    // 1. Setup Tenant & Package
    $package = Package::factory()->create(['price' => 100.00]);
    $tenant = Tenant::factory()->create(['package_id' => $package->id]);

    // 2. Setup Pricing (10 cents per request)
    App\Models\UsagePrice::create([
        'target_type' => Package::class,
        'target_id' => $package->id,
        'metric' => UsageMetric::REQUEST_COUNT,
        'unit_price' => 0.10,
    ]);

    // 3. Setup Limits (Alert at 80%)
    UsageLimit::create([
        'tenant_id' => $tenant->id,
        'metric' => UsageMetric::REQUEST_COUNT,
        'limit_value' => 1000,
        'alert_threshold' => 80,
        'block_on_limit' => true,
    ]);

    // 4. Record Usage (850 requests -> Should trigger alert)
    // We'll simulate rolled up data
    $start = now()->startOfMonth();
    UsageRollup::create([
        'tenant_id' => $tenant->id,
        'metric' => UsageMetric::REQUEST_COUNT,
        'period' => 'day',
        'period_start' => $start->copy()->addDay(),
        'value' => 850,
        'dimensions_hash' => 'test',
    ]);

    // 5. Run Alert Check
    $limitService = new UsageLimitService();
    // We expect a log warning here, spying on Log
    Illuminate\Support\Facades\Log::spy();
    $limitService->evaluateAlerts($tenant);

    Illuminate\Support\Facades\Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn ($msg) => str_contains($msg, 'Usage Alert') && str_contains($msg, '85%'));

    // 6. Generate Invoice
    $pricingService = new PricingService();
    $invoicingService = new InvoicingService($pricingService);

    $end = now()->endOfMonth();
    $invoice = $invoicingService->generate($tenant, $start, $end);

    // 7. verification
    // Base: $100.00
    // Usage: 850 * $0.10 = $85.00
    // Total should be $185.00 (tax excluded for simplicity)

    expect($invoice->status)->toBe('draft');
    expect((float) $invoice->subtotal)->toEqual(185.0);
    expect($invoice->items)->toHaveCount(2); // Base + Usage
});
