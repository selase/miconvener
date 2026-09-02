<?php

declare(strict_types=1);

use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\post;

test('superadmin can view tenant team via ajax', function () {
    // Setup
    $superAdminRole = Role::firstOrCreate(['name' => 'Superadmin', 'guard_name' => 'web']);
    $user = User::factory()->create();
    $user->assignRole($superAdminRole);

    $tenant = Tenant::factory()->create();
    $teamMember = User::factory()->create(['tenant_id' => $tenant->id]);
    $tenant->users()->attach($teamMember);

    actingAs($user);

    // Act
    $response = post(route('tenants.team.all', ['tenants' => $tenant->uuid]), [
        'start' => 0,
        'length' => 10,
        'columns' => [
            ['data' => 'first_name', 'searchable' => 'true', 'orderable' => 'true', 'search' => ['value' => '', 'regex' => 'false']],
        ],
        'order' => [
            ['column' => 0, 'dir' => 'asc'],
        ],
        'search' => ['value' => '', 'regex' => 'false'],
    ]);

    // Assert
    $response->assertStatus(200);
    $response->assertJsonStructure(['data', 'recordsTotal', 'recordsFiltered']);
    // Check if team member is in the response data
    $data = $response->json('data');
    expect(collect($data)->pluck('uuid'))->toContain((string) $teamMember->uuid);
});

test('impersonation button is visible for superadmin in team table', function () {
    // Setup
    $superAdminRole = Role::firstOrCreate(['name' => 'Superadmin', 'guard_name' => 'web']);
    $impersonatePermission = Permission::firstOrCreate(['name' => 'impersonate user', 'guard_name' => 'web', 'category' => 'system']);
    $superAdminRole->givePermissionTo($impersonatePermission);

    $user = User::factory()->create();
    $user->assignRole($superAdminRole);

    $tenant = Tenant::factory()->create();
    $teamMember = User::factory()->create(['tenant_id' => $tenant->id]);
    $role = Role::create(['name' => 'Member', 'guard_name' => 'web']);
    $teamMember->assignRole($role);
    $tenant->users()->attach($teamMember);

    actingAs($user);

    // Act
    $response = post(route('tenants.team.all', ['tenants' => $tenant->uuid]), [
        'start' => 0,
        'length' => 10,
        'columns' => [
            ['data' => 'first_name', 'searchable' => 'true', 'orderable' => 'true', 'search' => ['value' => '', 'regex' => 'false']],
        ],
        'order' => [
            ['column' => 0, 'dir' => 'asc'],
        ],
        'search' => ['value' => '', 'regex' => 'false'],
    ]);

    // Assert
    $response->assertStatus(200);
    $data = $response->json('data');
    expect($data)->toHaveCount(1);
    $memberRow = $data[0];

    // Check if the action column contains the impersonation link
    expect($memberRow['action'])->toContain('/impersonation/');
    expect($memberRow['action'])->toContain((string) $teamMember->id);
});

test('superadmin can start and stop impersonation', function () {
    // Setup
    $superAdminRole = Role::firstOrCreate(['name' => 'Superadmin', 'guard_name' => 'web']);
    $impersonatePermission = Permission::firstOrCreate(['name' => 'impersonate user', 'guard_name' => 'web', 'category' => 'system']);
    $superAdminRole->givePermissionTo($impersonatePermission);

    $admin = User::factory()->create();
    $admin->assignRole($superAdminRole);

    $targetUser = User::factory()->create();

    actingAs($admin);

    // Act: Start Impersonation
    $response = get(route('impersonation.impersonate', $targetUser->id));

    // Assert: Redirected and session has original user
    $response->assertRedirect(route('dashboard'));
    $this->assertAuthenticatedAs($targetUser);
    expect(session()->has('original_user'))->toBeTrue();
    expect(session('original_user'))->toBe($admin->id);

    // Act: Stop Impersonation
    $response = get(route('impersonation.stop'));

    // Assert: Redirected and session cleared
    $response->assertRedirect(route('dashboard'));
    $this->assertAuthenticatedAs($admin);
    expect(session()->has('original_user'))->toBeFalse();
});

test('superadmin can reset tenant context to global view', function () {
    // Setup
    $superAdminRole = Role::firstOrCreate(['name' => 'Superadmin', 'guard_name' => 'web']);
    $user = User::factory()->create();
    $user->assignRole($superAdminRole);

    // Simulate being in a tenant context
    $tenant = Tenant::factory()->create();
    $user->update(['tenant_id' => $tenant->id]);
    session()->put('tenant_id', $tenant->id);

    actingAs($user);

    // Act
    $response = get(route('tenants.reset'));

    // Assert
    $response->assertRedirect(route('dashboard'));

    // Verify session is cleared
    expect(session()->has('tenant_id'))->toBeFalse();

    // Verify user record is updated (refetch to get fresh data)
    $user->refresh();
    expect($user->tenant_id)->toBeNull();
});
