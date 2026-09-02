<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Services\Tenancy\FeatureService;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

beforeEach(function () {
    // Artisan::call('migrate', ...); // Handled by RefreshDatabase

    $this->tenant = Tenant::create([
        'name' => 'Test Tenant',
        'slug' => 'test-tenant',
        'isolation_mode' => 'shared',
    ]);

    app(TenantContext::class)->setTenant($this->tenant);
    $this->service = app(FeatureService::class);
});

test('it returns false if feature record missing', function () {
    expect($this->service->enabled('non-existent'))->toBeFalse();
});

test('it can enable and check feature', function () {
    $this->service->enable('test-feature');
    expect($this->service->enabled('test-feature'))->toBeTrue();
});

test('it can disable feature', function () {
    $this->service->enable('test-feature');
    $this->service->disable('test-feature');
    expect($this->service->enabled('test-feature'))->toBeFalse();
});

test('it isolates features by tenant', function () {
    $tenantB = Tenant::create([
        'name' => 'Tenant B',
        'slug' => 'tenant-b',
        'isolation_mode' => 'shared',
    ]);

    $this->service->enable('shared-feature');
    expect($this->service->enabled('shared-feature'))->toBeTrue();

    // Switch to Tenant B
    app(TenantContext::class)->setTenant($tenantB);
    expect($this->service->enabled('shared-feature'))->toBeFalse();

    // Enable for Tenant B
    $this->service->enable('shared-feature');
    expect($this->service->enabled('shared-feature'))->toBeTrue();

    // Switch back to Tenant A
    app(TenantContext::class)->setTenant($this->tenant);
    expect($this->service->enabled('shared-feature'))->toBeTrue();

    // Disable for Tenant A
    $this->service->disable('shared-feature');
    expect($this->service->enabled('shared-feature'))->toBeFalse();

    // Verify Tenant B remains enabled
    app(TenantContext::class)->setTenant($tenantB);
    expect($this->service->enabled('shared-feature'))->toBeTrue();
});
