<?php

declare(strict_types=1);

use App\Enum\TenantStatusEnum;
use App\Models\Package;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;

test('superadmin can create a tenant with a package', function () {
    $superadmin = User::factory()->create();
    // Assuming Superadmin role needs permissions
    $role = Role::firstOrCreate(['name' => 'Superadmin', 'guard_name' => 'web']);
    // No need to give permissions if Gate::before is set
    $superadmin->assignRole($role);

    $package = Package::create([
        'name' => 'Starter Plan',
        'slug' => 'starter-plan',
        'price' => 10.00,
        'interval' => 'month',
    ]);

    $response = $this->actingAs($superadmin)
        ->post(route('tenants.store'), [
            'name' => 'Test Tenant',
            'email' => 'tenant@test.com',
            'phone_number' => '1234567890',
            'country' => 'US',
            'city' => 'New York',
            'state_or_region' => 'NY',
            'zipcode' => '10001',
            'address' => '123 Main St',
            'status' => TenantStatusEnum::ACTIVE->value,
            'subdomain' => 'testtenant',
            'package_id' => $package->id,
            'isolation_mode' => 'shared',
            'db_driver' => 'mysql',
        ]);

    $response->assertSessionHasNoErrors();
    $response->assertRedirect(route('tenants.index'));

    $this->assertDatabaseHas('tenants', [
        'slug' => 'testtenant',
        'package_id' => $package->id,
    ]);
});

test('superadmin can update tenant package', function () {
    $superadmin = User::factory()->create();
    // Gate::before handles superadmin
    $role = Role::firstOrCreate(['name' => 'Superadmin', 'guard_name' => 'web']);
    $superadmin->assignRole($role);

    $package1 = Package::create(['name' => 'P1', 'slug' => 'p1', 'price' => 10]);
    $package2 = Package::create(['name' => 'P2', 'slug' => 'p2', 'price' => 20]);

    $tenant = Tenant::create([
        'name' => 'Existing Tenant',
        'email' => 'existing@test.com',
        'phone_number' => '1112223333',
        'status' => TenantStatusEnum::ACTIVE,
        'slug' => 'existing',
        'package_id' => $package1->id,
    ]);

    $response = $this->actingAs($superadmin)
        ->put(route('tenants.update', $tenant->uuid), [
            'name' => 'Existing Tenant Updated',
            'email' => 'existing@test.com',
            'phone_number' => '1112223333',
            'country' => 'US', // Required fields
            'city' => 'NY',
            'state_or_region' => 'NY',
            'zipcode' => '10001',
            'address' => '123 St',
            'status' => TenantStatusEnum::ACTIVE->value,
            'subdomain' => 'existing',
            'package_id' => $package2->id,
            'isolation_mode' => 'shared',
            'db_driver' => 'mysql',
        ]);

    $response->assertSessionHasNoErrors();

    $tenant->refresh();
    expect($tenant->package_id)->toEqual($package2->id);
});
