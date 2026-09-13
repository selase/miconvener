<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Services\Tenancy\TenantHealthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    refreshTenantDatabases();

    $this->tenant = Tenant::factory()->create([
        'isolation_mode' => 'shared',
        'db_driver' => 'pgsql',
        'slug' => 'health-test-'.uniqid(),
    ]);
});

it('can perform database health check', function () {
    $service = app(TenantHealthService::class);

    $result = $service->checkDatabase($this->tenant);

    expect($result['status'])->toBe('ok')
        ->and($result['has_schema'])->toBeTrue();
});

it('can perform storage health check', function () {
    Storage::fake('tenant');

    $service = app(TenantHealthService::class);
    $result = $service->checkStorage($this->tenant);

    expect($result['status'])->toBe('ok');
});

it('detects feature mismatch', function () {
    $package = App\Models\Package::factory()->create();
    $feature = App\Models\Feature::factory()->create();

    // Add feature to package
    $package->features()->attach($feature->id, ['value' => 'true']);

    $this->tenant->update(['package_id' => $package->id]);

    // Tenant has NO features synced yet
    $service = app(TenantHealthService::class);
    $result = $service->checkFeatures($this->tenant);

    expect($result['status'])->toBe('warning')
        ->and($result['message'])->toContain('Feature mismatch');
});

it('runs health check via livewire component', function () {
    Livewire::test('admin.tenant-health-check', ['tenant' => $this->tenant])
        ->call('runCheck')
        ->assertSet('loading', false)
        ->assertSee('Infrastructure Health')
        ->assertSee('Active');
});
