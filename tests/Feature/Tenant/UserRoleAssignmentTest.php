<?php

declare(strict_types=1);

use App\Enum\TenantStatusEnum;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

test('tenant administrator cannot assign superadmin role', function () {
    // Setup roles
    $superadminRole = Role::firstOrCreate(['name' => 'Superadmin', 'guard_name' => 'web']);
    $orgAdminRole = Role::firstOrCreate(['name' => 'Org Admin', 'guard_name' => 'web']);
    $orgMemberRole = Role::firstOrCreate(['name' => 'Org Member', 'guard_name' => 'web']);

    // Setup tenant and its admin
    $tenant = Tenant::create([
        'name' => 'Test Tenant',
        'email' => 'admin@test.com',
        'phone_number' => '1234567890',
        'slug' => 'test-tenant',
        'status' => TenantStatusEnum::ACTIVE,
    ]);

    $tenantAdmin = User::factory()->create(['email' => 't-admin@test.com']);
    $tenantAdmin->assignRole($orgAdminRole);
    $tenantAdmin->tenants()->attach($tenant->id);

    // Give permission to create user (otherwise it's 403 anyway)
    // Using the Gate::before bypass won't work for tenant admin as they aren't Superadmin
    // We need to give them 'create user' permission
    $permission = Permission::firstOrCreate(['name' => 'create user', 'guard_name' => 'web', 'category' => 'user']);
    $orgAdminRole->givePermissionTo($permission);

    // Attempt to create a user with Superadmin role
    $response = $this->actingAs($tenantAdmin)->post(route('users.store'), [
        'first_name' => 'John',
        'last_name' => 'Doe',
        'email' => 'john@doe.com',
        'phone_no' => '+233201234567', // Valid GH number for validation
        'role' => $superadminRole->id,
        'roles' => [$superadminRole->id],
        'status' => 'active',
        'tenant_id' => $tenant->id,
    ]);

    // Validation fails with custom message
    $response->assertStatus(302); // Redirect back on validation error
    $response->assertSessionHasErrors(['roles']);
});

test('tenant administrator can assign org member role', function () {
    $orgAdminRole = Role::firstOrCreate(['name' => 'Org Admin', 'guard_name' => 'web']);
    $orgMemberRole = Role::firstOrCreate(['name' => 'Org Member', 'guard_name' => 'web']);

    $tenant = Tenant::create([
        'name' => 'Test Tenant 2',
        'email' => 'admin2@test.com',
        'phone_number' => '1234567890',
        'slug' => 'test-tenant-2',
        'status' => TenantStatusEnum::ACTIVE,
    ]);

    $tenantAdmin = User::factory()->create(['email' => 't-admin2@test.com']);
    $tenantAdmin->assignRole($orgAdminRole);
    $tenantAdmin->tenants()->attach($tenant->id);

    $permission = Permission::firstOrCreate(['name' => 'create user', 'guard_name' => 'web', 'category' => 'user']);
    $orgAdminRole->givePermissionTo($permission);

    $response = $this->actingAs($tenantAdmin)->post(route('users.store'), [
        'first_name' => 'Jane',
        'last_name' => 'Doe',
        'email' => 'jane@doe.com',
        'phone_no' => '+233201234567',
        'role' => $orgMemberRole->id,
        'roles' => [$orgMemberRole->id],
        'status' => 'active',
        'tenant_id' => $tenant->id,
    ]);

    $response->assertStatus(200);
    $this->assertDatabaseHas('users', ['email' => 'jane@doe.com']);

    $user = User::where('email', 'jane@doe.com')->first();
    expect($user->hasRole('Org Member'))->toBeTrue();
});
