<?php

declare(strict_types=1);

use App\Enum\UsageMetric;
use App\Models\Tenant;
use App\Models\UsageLimit;
use App\Models\UsageRollup;
use App\Services\Tenancy\UsageLimitService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->service = new UsageLimitService();

    $this->tenant = Tenant::factory()->create();

    // Seed some usage
    UsageRollup::create([
        'tenant_id' => $this->tenant->id,
        'metric' => UsageMetric::REQUEST_COUNT,
        'period' => 'day',
        'period_start' => now()->startOfDay(),
        'value' => 800,
        'dimensions_hash' => 'test',
    ]);
});

test('it correctly checks compliance with limits', function () {
    // Limit = 1000. Usage = 800.
    UsageLimit::create([
        'tenant_id' => $this->tenant->id,
        'metric' => UsageMetric::REQUEST_COUNT,
        'limit_value' => 1000,
        'period' => 'month',
    ]);

    // +100 usage = 900 <= 1000. OK.
    expect($this->service->isWithinLimits($this->tenant, UsageMetric::REQUEST_COUNT, 100))->toBeTrue();

    // +300 usage = 1100 > 1000. Fail.
    expect($this->service->isWithinLimits($this->tenant, UsageMetric::REQUEST_COUNT, 300))->toBeFalse();
});

test('middleware enforces blocking limits', function () {
    UsageLimit::create([
        'tenant_id' => $this->tenant->id,
        'metric' => UsageMetric::REQUEST_COUNT,
        'limit_value' => 500, // Limit exceeded (current 800)
        'period' => 'month',
        'block_on_limit' => true,
    ]);

    Route::get('/test-limit', function () {
        return 'ok';
    })->middleware([App\Http\Middleware\ResolveTenant::class, App\Http\Middleware\EnforceUsageLimits::class]);

    $this->get('/test-limit', ['X-Tenant' => $this->tenant->id])
        ->assertStatus(429)
        ->assertJsonFragment(['error' => 'Usage limit exceeded']);
});

test('middleware allows if blocking is disabled', function () {
    UsageLimit::create([
        'tenant_id' => $this->tenant->id,
        'metric' => UsageMetric::REQUEST_COUNT,
        'limit_value' => 500,
        'block_on_limit' => false,
    ]);

    Route::get('/test-limit-nonblocking', function () {
        return 'ok';
    })->middleware([App\Http\Middleware\ResolveTenant::class, App\Http\Middleware\EnforceUsageLimits::class]);

    $this->get('/test-limit-nonblocking', ['X-Tenant' => $this->tenant->id])
        ->assertStatus(200);
});

test('it triggers alerts when threshold reached', function () {
    Log::spy();

    // Limit = 1000. Usage = 800. (80%)
    // Threshold = 75%. Should alert.
    UsageLimit::create([
        'tenant_id' => $this->tenant->id,
        'metric' => UsageMetric::REQUEST_COUNT,
        'limit_value' => 1000,
        'alert_threshold' => 75,
    ]);

    $this->service->evaluateAlerts($this->tenant);

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(function ($message) {
            return str_contains($message, 'Usage Alert for Tenant') && str_contains($message, '80% capacity');
        });
});
