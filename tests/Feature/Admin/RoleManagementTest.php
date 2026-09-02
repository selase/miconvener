<?php

declare(strict_types=1);

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Str;

test('superadmin can view role management page', function () {
    $superadmin = User::factory()->create();
    $role = Role::create(['name' => 'Superadmin', 'guard_name' => 'web']);
    $superadmin->assignRole($role);

    $this->actingAs($superadmin)
        ->get(route('roles.index'))
        ->assertStatus(200)
        ->assertSee('Roles & Permissions');
});

test('non-superadmin cannot view role management page', function () {
    $user = User::factory()->create();
    // No role assigned, or assign a non-superadmin role

    $this->actingAs($user)
        ->get(route('roles.index'))
        ->assertStatus(403); // Assuming middleware blocks it, or verify redirection/error
});

test('role list returns datatables json for ajax', function () {
    $superadmin = User::factory()->create();
    $role = Role::create(['name' => 'Superadmin', 'guard_name' => 'web']);
    $superadmin->assignRole($role);

    Role::create(['name' => 'Test Global Role', 'guard_name' => 'web', 'tenant_id' => null]);
    Role::create(['name' => 'Test Tenant Role', 'guard_name' => 'web', 'tenant_id' => Str::uuid()]);

    $response = $this->actingAs($superadmin)
        ->getJson(route('roles.index'), ['X-Requested-With' => 'XMLHttpRequest']);

    $response->assertStatus(200)
        ->assertJsonFragment(['name' => 'Test Global Role'])
        ->assertJsonMissing(['name' => 'Test Tenant Role']);
});
