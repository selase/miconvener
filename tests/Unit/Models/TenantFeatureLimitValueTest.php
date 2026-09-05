<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\TenantFeature;

test('featureLimitValue returns the configured limit for an enabled limit feature', function () {
    $tenant = Tenant::factory()->create();

    TenantFeature::create([
        'tenant_id' => $tenant->id,
        'feature_key' => 'events_in_flight',
        'enabled' => true,
        'meta' => ['type' => 'limit', 'value' => 3],
    ]);

    expect($tenant->featureLimitValue('events_in_flight'))->toBe(3);
});

test('featureLimitValue returns null when the feature is disabled', function () {
    $tenant = Tenant::factory()->create();

    TenantFeature::create([
        'tenant_id' => $tenant->id,
        'feature_key' => 'events_in_flight',
        'enabled' => false,
        'meta' => ['type' => 'limit', 'value' => 3],
    ]);

    expect($tenant->featureLimitValue('events_in_flight'))->toBeNull();
});

test('featureLimitValue returns null when the feature does not exist for the tenant', function () {
    $tenant = Tenant::factory()->create();

    expect($tenant->featureLimitValue('events_in_flight'))->toBeNull();
});

test('featureLimitValue returns null (unlimited) when the stored value is negative', function () {
    $tenant = Tenant::factory()->create();

    TenantFeature::create([
        'tenant_id' => $tenant->id,
        'feature_key' => 'events_in_flight',
        'enabled' => true,
        'meta' => ['type' => 'limit', 'value' => -1],
    ]);

    expect($tenant->featureLimitValue('events_in_flight'))->toBeNull();
});

test('featureLimitValue returns null for a boolean-type feature', function () {
    $tenant = Tenant::factory()->create();

    TenantFeature::create([
        'tenant_id' => $tenant->id,
        'feature_key' => 'custom-domains',
        'enabled' => true,
        'meta' => ['type' => 'boolean', 'value' => true],
    ]);

    expect($tenant->featureLimitValue('custom-domains'))->toBeNull();
});
