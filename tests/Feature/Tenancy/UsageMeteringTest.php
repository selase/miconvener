<?php

declare(strict_types=1);

use App\Enum\UsageMetric;
use App\Models\Tenant;
use App\Models\UsageEvent;
use App\Models\UsageRollup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->landlordTenant = Tenant::factory()->create([
        'name' => 'Landlord Tenant',
        'slug' => 'landlord-tenant',
        'isolation_mode' => 'shared',
    ]);

    $this->otherTenant = Tenant::factory()->create([
        'name' => 'Other Tenant',
        'slug' => 'other-tenant',
        'isolation_mode' => 'shared',
    ]);

    // Use /sample-product which is public and in the web group
    $this->testUrl = '/sample-product';
});

test('it records http request usage', function () {
    Route::get('/test-usage', function () {
        return response()->json(['message' => 'ok']);
    })->middleware([App\Http\Middleware\ResolveTenant::class, App\Http\Middleware\MeterRequestUsage::class]);

    $url = '/test-usage';

    // 1. Act as Landlord Tenant
    $this->get($url, [
        'X-Tenant' => $this->landlordTenant->id,
    ])->assertOk();

    // 2. Act as Other Tenant
    $this->get($url, [
        'X-Tenant' => $this->otherTenant->id,
    ])->assertOk();

    // 3. Verify Events
    expect(UsageEvent::where('type', UsageMetric::REQUEST_COUNT)->count())->toBe(2);
    expect(UsageEvent::where('tenant_id', $this->landlordTenant->id)->count())->toBeGreaterThan(0);
    expect(UsageEvent::where('tenant_id', $this->otherTenant->id)->count())->toBeGreaterThan(0);

    // 4. Verify Rollups
    expect(UsageRollup::where('metric', UsageMetric::REQUEST_COUNT)->count())->toBe(2);

    $landlordRollup = UsageRollup::where('tenant_id', $this->landlordTenant->id)
        ->where('metric', UsageMetric::REQUEST_COUNT)
        ->first();

    expect($landlordRollup->value)->toEqual(1);
    expect($landlordRollup->dimensions)->toHaveKey('route', 'GET test-usage');
});

test('it records job usage with TenantAwareJob middleware', function () {
    // We'll create a simple job class at the bottom or use an existing one
    $job = new Tests\Fixtures\TestTenantJob($this->landlordTenant->id);

    dispatch_sync($job);

    expect(UsageRollup::where('metric', UsageMetric::JOB_COUNT)->count())->toBe(1);
    $rollup = UsageRollup::where('tenant_id', $this->landlordTenant->id)
        ->where('metric', UsageMetric::JOB_COUNT)
        ->first();

    expect($rollup->value)->toEqual(1);
});

test('it audits storage usage', function () {
    Artisan::call('tenants:audit-storage');

    // Should have a storage rollup for each tenant (initially 0 bytes)
    expect(UsageRollup::where('metric', UsageMetric::STORAGE_BYTES)->count())->toBe(2);
});

test('it audits database usage', function () {
    Artisan::call('tenants:audit-db');

    // Should have a DB rollup for each tenant
    expect(UsageRollup::where('metric', UsageMetric::DB_BYTES)->count())->toBe(2);
});
