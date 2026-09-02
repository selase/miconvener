<?php

declare(strict_types=1);

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

beforeEach(function () {
    // Artisan::call('migrate', ...); // Handled by RefreshDatabase
});

test('tenant model uses landlord connection', function () {
    $tenant = new Tenant();
    expect($tenant->getConnectionName())->toBe('landlord');
});

test('tenant model has correct isolation helpers', function () {
    $tenant = new Tenant(['isolation_mode' => 'shared']);
    expect($tenant->requiresDedicatedDb())->toBeFalse();

    $tenant->isolation_mode = 'db_per_tenant';
    expect($tenant->requiresDedicatedDb())->toBeTrue();

    $tenant->isolation_mode = 'byo';
    expect($tenant->requiresDedicatedDb())->toBeTrue();
});

test('tenant model has encryption helper', function () {
    $tenant = new Tenant(['encryption_at_rest' => false]);
    expect($tenant->encryptionAtRestEnabled())->toBeFalse();

    $tenant->encryption_at_rest = true;
    expect($tenant->encryptionAtRestEnabled())->toBeTrue();
});

test('tenant model correctly checks for enabled features', function () {
    $tenant = Tenant::create([
        'name' => 'Test Tenant',
        'slug' => 'test-tenant',
        'isolation_mode' => 'shared',
    ]);

    expect($tenant->featureEnabled('test-feature'))->toBeFalse();

    $tenant->features()->create([
        'feature_key' => 'test-feature',
        'enabled' => true,
    ]);

    expect($tenant->featureEnabled('test-feature'))->toBeTrue();

    $tenant->features()->where('feature_key', 'test-feature')->update(['enabled' => false]);

    expect($tenant->featureEnabled('test-feature'))->toBeFalse();
});
