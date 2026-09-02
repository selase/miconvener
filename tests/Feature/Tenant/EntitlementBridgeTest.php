<?php

declare(strict_types=1);

namespace Tests\Feature\Tenant;

use App\Models\Feature;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantFeature;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

test('gate blocks permission when feature is not entitled', function () {
    $tenant = Tenant::factory()->create(['slug' => 'test-tenant']);
    $user = User::factory()->create(['tenant_id' => $tenant->id]);

    // Create a feature and a permission
    $feature = Feature::create([
        'name' => 'Advanced Analytics',
        'slug' => 'advanced-analytics',
        'type' => 'boolean',
        'uuid' => (string) str()->uuid(),
    ]);

    $permName = 'perm-'.uniqid();

    $permission = Permission::create([
        'name' => $permName,
        'guard_name' => 'web',
        'category' => 'analytics',
        'uuid' => (string) str()->uuid(),
    ]);

    // Link permission to feature
    $feature->permissions()->attach($permission->id);

    // Verify relationship
    expect($feature->fresh()->permissions->pluck('name')->toArray())->toContain($permName);

    // Give user the permission
    setPermissionsTeamId($tenant->id);
    $user->givePermissionTo($permission);

    // Set context
    app(TenantContext::class)->setTenant($tenant);

    // Act as user
    $this->actingAs($user);

    // Gate::allows should return false even if user has permission
    expect($user->can($permName))->toBeFalse();
});

test('gate allows permission when feature is entitled', function () {
    $tenant = Tenant::factory()->create(['slug' => 'test-tenant-pro']);
    $user = User::factory()->create(['tenant_id' => $tenant->id]);

    // Create a feature and a permission
    $feature = Feature::create([
        'name' => 'Advanced Analytics Pro',
        'slug' => 'advanced-analytics-pro',
        'type' => 'boolean',
        'uuid' => (string) str()->uuid(),
    ]);

    $permission = Permission::create([
        'name' => 'view-advanced-analytics-pro',
        'guard_name' => 'web',
        'category' => 'analytics',
        'uuid' => (string) str()->uuid(),
    ]);

    // Link permission to feature
    $feature->permissions()->attach($permission->id);

    // Enable feature for tenant
    TenantFeature::create([
        'tenant_id' => $tenant->id,
        'feature_key' => 'advanced-analytics-pro',
        'enabled' => true,
    ]);

    // Give user the permission
    setPermissionsTeamId($tenant->id);
    $user->givePermissionTo($permission);

    // Set context
    app(TenantContext::class)->setTenant($tenant);

    // Act as user
    $this->actingAs($user);

    // Gate::allows should return true
    expect(Gate::allows('view-advanced-analytics-pro'))->toBeTrue();
});

test('superadmin bypasses entitlement checks', function () {
    $role = Role::create(['name' => 'Superadmin', 'guard_name' => 'web', 'uuid' => (string) str()->uuid()]);

    $superadmin = User::factory()->create();
    // Superadmin role assignment is usually on landlord context (tenant_id = null)
    setPermissionsTeamId(null);
    $superadmin->assignRole($role);

    $tenant = Tenant::factory()->create(['slug' => 'test-tenant-bypass']);

    // Create a feature and a permission
    $feature = Feature::create([
        'name' => 'Extreme Power',
        'slug' => 'extreme-power',
        'type' => 'boolean',
        'uuid' => (string) str()->uuid(),
    ]);

    $permission = Permission::create([
        'name' => 'use-extreme-power',
        'guard_name' => 'web',
        'category' => 'admin',
        'uuid' => (string) str()->uuid(),
    ]);

    // Link permission to feature
    $feature->permissions()->attach($permission->id);

    // Tenant does NOT have feature

    // Set context
    app(TenantContext::class)->setTenant($tenant);

    // Act as superadmin
    $this->actingAs($superadmin);

    // Superadmin should have access even if tenant is not entitled
    expect(Gate::allows('use-extreme-power'))->toBeTrue();
});
