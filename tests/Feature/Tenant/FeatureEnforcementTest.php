<?php

declare(strict_types=1);

use App\Enum\TenantStatusEnum;
use App\Models\Tenant;
use App\Models\TenantFeature;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    // Route for testing
    Route::get('/test-feature-protected', function () {
        return 'allowed';
    })->middleware(['web', 'feature:test_feature']);
});

test('middleware allows access when feature is enabled', function () {
    $tenant = Tenant::create([
        'name' => 'Enabled Tenant',
        'email' => 'enabled@test.com',
        'phone_number' => '1234567890',
        'slug' => 'enabled',
        'status' => TenantStatusEnum::ACTIVE,
    ]);

    TenantFeature::create([
        'tenant_id' => $tenant->id,
        'feature_key' => 'test_feature',
        'enabled' => true,
    ]);

    // Simulating being on the tenant subdomain
    $url = 'http://enabled.'.config('app.domain').'/test-feature-protected';

    // We need to make sure the ResolveTenant middleware picks it up
    // In tests, we can manually set the context
    app(TenantContext::class)->setTenant($tenant);

    $response = $this->get('/test-feature-protected');

    $response->assertStatus(200);
    $response->assertSee('allowed');
});

test('middleware blocks access when feature is disabled', function () {
    $tenant = Tenant::create([
        'name' => 'Disabled Tenant',
        'email' => 'disabled@test.com',
        'phone_number' => '1234567890',
        'slug' => 'disabled',
        'status' => TenantStatusEnum::ACTIVE,
    ]);

    // Not creating the feature record or setting it to false
    TenantFeature::create([
        'tenant_id' => $tenant->id,
        'feature_key' => 'test_feature',
        'enabled' => false,
    ]);

    app(TenantContext::class)->setTenant($tenant);

    $response = $this->get('/test-feature-protected');

    $response->assertStatus(403);
});

test('middleware blocks access when feature record is missing', function () {
    $tenant = Tenant::create([
        'name' => 'Missing Tenant',
        'email' => 'missing@test.com',
        'phone_number' => '1234567890',
        'slug' => 'missing',
        'status' => TenantStatusEnum::ACTIVE,
    ]);

    // No feature record created

    app(TenantContext::class)->setTenant($tenant);

    $response = $this->get('/test-feature-protected');

    $response->assertStatus(403);
});
