<?php

declare(strict_types=1);

use App\Enum\TenantStatusEnum;
use App\Models\Feature;
use App\Models\Package;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;

test('tenant features are synced when a package is assigned', function () {
    // Setup
    $superadmin = User::factory()->create();
    $role = Role::firstOrCreate(['name' => 'Superadmin', 'guard_name' => 'web']);
    $superadmin->assignRole($role);

    $feature = Feature::create([
        'name' => 'AI Chat',
        'slug' => 'ai_chat',
        'type' => 'boolean',
    ]);

    $package = Package::create([
        'name' => 'Pro Plan',
        'slug' => 'pro-plan',
        'price' => 50,
        'interval' => 'month',
        'is_active' => true,
    ]);

    $package->features()->attach($feature->id, ['value' => 'true']);

    // Create Tenant via Controller
    $response = $this->actingAs($superadmin)->post(route('tenants.store'), [
        'name' => 'Sync Tenant',
        'email' => 'sync@test.com',
        'phone_number' => '1234567890',
        'country' => 'US',
        'city' => 'NY',
        'state_or_region' => 'NY',
        'zipcode' => '10001',
        'address' => '123 Sync St',
        'status' => TenantStatusEnum::ACTIVE->value,
        'subdomain' => 'synctenant',
        'package_id' => $package->id,
        'isolation_mode' => 'shared',
        'db_driver' => 'mysql',
    ]);

    $response->assertSessionHasNoErrors();
    $tenant = Tenant::where('slug', 'synctenant')->first();

    // Verify sync
    $this->assertDatabaseHas('tenant_features', [
        'tenant_id' => $tenant->id,
        'feature_key' => 'ai_chat',
        'enabled' => true,
    ]);
});

test('tenant features are updated when package changes', function () {
    $superadmin = User::factory()->create();
    $role = Role::firstOrCreate(['name' => 'Superadmin', 'guard_name' => 'web']);
    $superadmin->assignRole($role);

    $f1 = Feature::create(['name' => 'Feature 1', 'slug' => 'f1', 'type' => 'boolean']);
    $f2 = Feature::create(['name' => 'Feature 2', 'slug' => 'f2', 'type' => 'boolean']);

    $p1 = Package::create(['name' => 'P1', 'slug' => 'p1', 'price' => 10, 'is_active' => true]);
    $p1->features()->attach($f1->id, ['value' => 'true']);

    $p2 = Package::create(['name' => 'P2', 'slug' => 'p2', 'price' => 20, 'is_active' => true]);
    $p2->features()->attach($f2->id, ['value' => 'true']);

    // Create tenant with P1
    $tenant = Tenant::create([
        'name' => 'Update Sync Tenant',
        'email' => 'updatesync@test.com',
        'phone_number' => '1112223333',
        'slug' => 'updatesync',
        'status' => TenantStatusEnum::ACTIVE,
        'package_id' => $p1->id,
        'isolation_mode' => 'shared',
        'db_driver' => 'mysql',
    ]);
    $tenant->syncFeaturesFromPackage();

    expect($tenant->featureEnabled('f1'))->toBeTrue();
    expect($tenant->featureEnabled('f2'))->toBeFalse();

    // Update to P2 via Controller
    $response = $this->actingAs($superadmin)->put(route('tenants.update', $tenant->uuid), [
        'name' => 'Update Sync Tenant',
        'email' => 'updatesync@test.com',
        'phone_number' => '1112223333',
        'country' => 'US',
        'city' => 'NY',
        'state_or_region' => 'NY',
        'zipcode' => '10001',
        'address' => '123 Sync St',
        'status' => TenantStatusEnum::ACTIVE->value,
        'subdomain' => 'updatesync',
        'package_id' => $p2->id,
        'isolation_mode' => 'shared',
        'db_driver' => 'mysql',
    ]);

    $response->assertSessionHasNoErrors();
    $tenant->refresh();

    // Verify f1 is disabled and f2 is enabled
    $this->assertDatabaseHas('tenant_features', [
        'tenant_id' => $tenant->id,
        'feature_key' => 'f1',
        'enabled' => false,
    ]);
    $this->assertDatabaseHas('tenant_features', [
        'tenant_id' => $tenant->id,
        'feature_key' => 'f2',
        'enabled' => true,
    ]);
});
