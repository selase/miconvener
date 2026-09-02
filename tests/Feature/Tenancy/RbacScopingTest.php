<?php

declare(strict_types=1);

use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;

beforeEach(function () {
    Artisan::call('migrate', [
        '--path' => 'database/migrations/landlord',
        '--realpath' => true,
    ]);
});

test('user can have different roles in different tenants', function () {
    $tenantA = Tenant::create(['name' => 'Tenant A', 'slug' => 'tenant-a', 'isolation_mode' => 'shared']);
    $tenantB = Tenant::create(['name' => 'Tenant B', 'slug' => 'tenant-b', 'isolation_mode' => 'shared']);

    $user = User::factory()->create();

    $roleAdmin = Role::create(['name' => 'admin']);
    $roleEditor = Role::create(['name' => 'editor']);

    // Set tenant context for Spatie Permission
    setPermissionsTeamId($tenantA->id);
    $user->assignRole($roleAdmin);

    setPermissionsTeamId($tenantB->id);
    $user->assignRole($roleEditor);

    // Verify roles are scoped
    setPermissionsTeamId($tenantA->id);
    $user->unsetRelation('roles'); // Clear cached roles
    expect($user->hasRole('admin'))->toBeTrue();
    expect($user->hasRole('editor'))->toBeFalse();

    setPermissionsTeamId($tenantB->id);
    $user->unsetRelation('roles'); // Clear cached roles
    expect($user->hasRole('admin'))->toBeFalse();
    expect($user->hasRole('editor'))->toBeTrue();
});

test('switching tenants changes effective permissions', function () {
    $tenantA = Tenant::create(['name' => 'Tenant A', 'slug' => 'tenant-a', 'isolation_mode' => 'shared']);
    $tenantB = Tenant::create(['name' => 'Tenant B', 'slug' => 'tenant-b', 'isolation_mode' => 'shared']);

    $user = User::factory()->create();

    $permEdit = Permission::create(['name' => 'edit articles', 'category' => 'test']);
    $permPublish = Permission::create(['name' => 'publish articles', 'category' => 'test']);

    // Grant 'edit' in Tenant A
    setPermissionsTeamId($tenantA->id);
    $user->givePermissionTo($permEdit);

    // Grant 'publish' in Tenant B
    setPermissionsTeamId($tenantB->id);
    $user->givePermissionTo($permPublish);

    // Verify in Tenant A
    setPermissionsTeamId($tenantA->id);
    $user->unsetRelation('permissions');
    expect($user->hasPermissionTo('edit articles'))->toBeTrue();
    expect($user->hasPermissionTo('publish articles'))->toBeFalse();

    // Verify in Tenant B
    setPermissionsTeamId($tenantB->id);
    $user->unsetRelation('permissions');
    expect($user->hasPermissionTo('edit articles'))->toBeFalse();
    expect($user->hasPermissionTo('publish articles'))->toBeTrue();
});
