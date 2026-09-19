<?php

declare(strict_types=1);

use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\PermissionRegistrar;

/**
 * Nobody hands out more than they hold. Both escalation paths below were
 * reproduced against the code before this boundary existed: a custom role
 * holding only "read user" and "update user" promoted itself to Org Admin,
 * and a custom role holding "create role" minted a role with permissions it
 * did not have.
 */
beforeEach(function (): void {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
});

/**
 * @param  list<string>  $permissions
 * @return array{0: Tenant, 1: User, 2: string}
 */
function ceilingActor(array $permissions, string $slug = 'ceiling'): array
{
    $tenant = Tenant::factory()->create(['slug' => $slug, 'isolation_mode' => 'shared']);
    setPermissionsTeamId($tenant->id);
    $role = Role::create(['name' => 'Desk', 'guard_name' => 'web', 'tenant_id' => $tenant->id]);
    $role->givePermissionTo($permissions);

    $actor = User::factory()->create([
        'tenant_id' => $tenant->id,
        'phone_no' => '+233201234571',
        'status' => User::STATUS_ACTIVE,
    ]);
    $actor->assignRole($role);
    $tenant->users()->attach($actor->id);

    return [$tenant, $actor, "{$slug}.".mb_ltrim((string) config('session.domain'), '.')];
}

function ceilingMember(Tenant $tenant, string $roleName, string $phone = '+233201234572'): User
{
    setPermissionsTeamId($tenant->id);
    $member = User::factory()->create([
        'tenant_id' => $tenant->id,
        'phone_no' => $phone,
        'status' => User::STATUS_ACTIVE,
    ]);
    $member->assignRole($roleName);
    $tenant->users()->attach($member->id);

    return $member;
}

function freshRoleNames(User $user, Tenant $tenant): array
{
    setPermissionsTeamId($tenant->id);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user->fresh()->roles->pluck('name')->all();
}

function memberPayload(User $user, int $roleId): array
{
    return [
        'first_name' => $user->first_name,
        'last_name' => $user->last_name,
        'email' => $user->email,
        'phone_no' => (string) $user->phone_no,
        'role' => $roleId,
        'status' => User::STATUS_ACTIVE,
    ];
}

function systemRoleId(string $name): int
{
    return Role::whereNull('tenant_id')->where('name', $name)->firstOrFail()->id;
}

test('a custom role cannot promote itself to Org Admin', function () {
    [$tenant, $actor, $host] = ceilingActor(['read user', 'update user']);

    $this->actingAs($actor)
        ->put("http://{$host}/users/{$actor->uuid}", memberPayload($actor, systemRoleId('Org Admin')), ['HTTP_HOST' => $host])
        ->assertSessionHasErrors('role');

    expect(freshRoleNames($actor, $tenant))->toBe(['Desk']);
});

test('a custom role cannot give a colleague a stronger role than its own', function () {
    [$tenant, $actor, $host] = ceilingActor(['read user', 'update user']);
    $colleague = ceilingMember($tenant, 'Desk');

    $this->actingAs($actor)
        ->put("http://{$host}/users/{$colleague->uuid}", memberPayload($colleague, systemRoleId('Org Admin')), ['HTTP_HOST' => $host])
        ->assertSessionHasErrors('role');

    expect(freshRoleNames($colleague, $tenant))->toBe(['Desk']);
});

test('a custom role cannot invite someone straight into a stronger role', function () {
    [, $actor, $host] = ceilingActor(['read user', 'create user']);

    $this->actingAs($actor)
        ->post("http://{$host}/users", [
            'first_name' => 'New', 'last_name' => 'Person', 'email' => 'new@example.com',
            'phone_no' => '+233201234579', 'role' => systemRoleId('Org Admin'), 'status' => User::STATUS_ACTIVE,
        ], ['HTTP_HOST' => $host])
        ->assertSessionHasErrors('role');

    expect(User::where('email', 'new@example.com')->exists())->toBeFalse();
});

test('nobody can change their own role, even downward', function () {
    [$tenant] = ceilingActor(['read user'], 'self-role');
    $admin = ceilingMember($tenant, 'Org Admin');
    $host = 'self-role.'.mb_ltrim((string) config('session.domain'), '.');

    $this->actingAs($admin)
        ->put("http://{$host}/users/{$admin->uuid}", memberPayload($admin, Role::where('tenant_id', $tenant->id)->where('name', 'Desk')->value('id')), ['HTTP_HOST' => $host])
        ->assertSessionHasErrors('role');

    expect(freshRoleNames($admin, $tenant))->toBe(['Org Admin']);
});

test('editing your own details while keeping your role still works', function () {
    [$tenant] = ceilingActor(['read user'], 'self-details');
    $admin = ceilingMember($tenant, 'Org Admin');
    $host = 'self-details.'.mb_ltrim((string) config('session.domain'), '.');

    $payload = memberPayload($admin, systemRoleId('Org Admin'));
    $payload['first_name'] = 'Renamed';

    $this->actingAs($admin)
        ->put("http://{$host}/users/{$admin->uuid}", $payload, ['HTTP_HOST' => $host])
        ->assertSessionHasNoErrors();

    expect($admin->fresh()->first_name)->toBe('Renamed');
    expect(freshRoleNames($admin, $tenant))->toBe(['Org Admin']);
});

test('a member whose role reaches beyond yours is out of your hands', function () {
    [$tenant, $actor, $host] = ceilingActor(['read user', 'update user', 'delete user']);
    $admin = ceilingMember($tenant, 'Org Admin');

    // Editing is a way to demote them; removing them is the end state of it.
    $this->actingAs($actor)
        ->put("http://{$host}/users/{$admin->uuid}", memberPayload($admin, systemRoleId('Org Admin')), ['HTTP_HOST' => $host])
        ->assertForbidden();

    $this->actingAs($actor)
        ->delete("http://{$host}/users/{$admin->uuid}", [], ['HTTP_HOST' => $host])
        ->assertForbidden();

    expect(User::find($admin->id))->not->toBeNull();
    expect(freshRoleNames($admin, $tenant))->toBe(['Org Admin']);
});

test('the team page offers only roles and actions the server would allow', function () {
    [$tenant, $actor, $host] = ceilingActor(['read user', 'update user']);
    $admin = ceilingMember($tenant, 'Org Admin');

    $this->actingAs($actor)
        ->get("http://{$host}/users", ['HTTP_HOST' => $host])
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('roles', fn ($roles): bool => collect($roles)->pluck('name')->all() === ['Desk'])
            ->where('users.data', function ($users) use ($admin, $actor): bool {
                $rows = collect($users)->keyBy('uuid');

                $admin = $rows[(string) $admin->uuid];
                $self = $rows[(string) $actor->uuid];

                return $admin['can_edit'] === false
                    && $admin['can_remove'] === false
                    && $self['can_change_role'] === false;
            }));
});

test('a custom role cannot mint a role holding permissions it lacks', function () {
    [$tenant, $actor, $host] = ceilingActor(['read role', 'create role']);

    $this->actingAs($actor)
        ->post("http://{$host}/roles", [
            'name' => 'Minted',
            'permissions' => ['delete user', 'manage organization settings', 'read finance'],
        ], ['HTTP_HOST' => $host])
        ->assertSessionHasErrors('permissions');

    expect(Role::where('tenant_id', $tenant->id)->where('name', 'Minted')->exists())->toBeFalse();
});

test('a custom role can still create a role from permissions it holds', function () {
    [$tenant, $actor, $host] = ceilingActor(['read role', 'create role', 'read user']);

    $this->actingAs($actor)
        ->post("http://{$host}/roles", [
            'name' => 'Viewer',
            'permissions' => ['read user'],
        ], ['HTTP_HOST' => $host])
        ->assertSessionHasNoErrors();

    $viewer = Role::where('tenant_id', $tenant->id)->where('name', 'Viewer')->firstOrFail();
    expect($viewer->permissions->pluck('name')->all())->toBe(['read user']);
});

test('a custom role stronger than its editor cannot be edited by them', function () {
    [$tenant, $actor, $host] = ceilingActor(['read role', 'update role']);
    setPermissionsTeamId($tenant->id);
    $strong = Role::create(['name' => 'Strong', 'guard_name' => 'web', 'tenant_id' => $tenant->id]);
    $strong->givePermissionTo(['delete user', 'read finance']);

    $this->actingAs($actor)
        ->put("http://{$host}/roles/{$strong->id}", [
            'name' => 'Strong',
            'permissions' => ['delete user', 'read finance', 'manage organization settings'],
        ], ['HTTP_HOST' => $host])
        ->assertSessionHasErrors('permissions');

    expect($strong->fresh()->permissions->pluck('name')->sort()->values()->all())->toBe(['delete user', 'read finance']);
});

test('duplicating a system role is bounded the same way', function () {
    [$tenant, $actor, $host] = ceilingActor(['read role', 'create role', 'read user']);

    $this->actingAs($actor)
        ->post("http://{$host}/roles/".systemRoleId('Org Admin').'/duplicate', [
            'name' => 'Admin copy',
            'source_role_id' => systemRoleId('Org Admin'),
            'permissions' => ['read user', 'delete user', 'manage organization settings'],
        ], ['HTTP_HOST' => $host])
        ->assertSessionHasErrors('permissions');

    expect(Role::where('tenant_id', $tenant->id)->where('name', 'Admin copy')->exists())->toBeFalse();
});

test('the roles page offers only permissions the user can grant', function () {
    [$tenant, $actor, $host] = ceilingActor(['read role', 'create role', 'update role', 'read user']);
    setPermissionsTeamId($tenant->id);
    $strong = Role::create(['name' => 'Strong', 'guard_name' => 'web', 'tenant_id' => $tenant->id]);
    $strong->givePermissionTo(['delete user']);

    $this->actingAs($actor)
        ->get("http://{$host}/roles", ['HTTP_HOST' => $host])
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('permissions', fn ($names): bool => collect($names)->diff(['read role', 'create role', 'update role', 'read user'])->isEmpty())
            ->where('customRoles', fn ($roles): bool => collect($roles)->firstWhere('name', 'Strong')['can_manage'] === false
                && collect($roles)->firstWhere('name', 'Desk')['can_manage'] === true));
});

test('an owner is not bounded: they can assign Org Admin and build any tenant role', function () {
    [$tenant] = ceilingActor(['read user'], 'owner-free');
    $owner = ceilingMember($tenant, 'Org Superadmin');
    $colleague = ceilingMember($tenant, 'Desk', '+233201234573');
    $host = 'owner-free.'.mb_ltrim((string) config('session.domain'), '.');

    $this->actingAs($owner)
        ->put("http://{$host}/users/{$colleague->uuid}", memberPayload($colleague, systemRoleId('Org Admin')), ['HTTP_HOST' => $host])
        ->assertSessionHasNoErrors();

    expect(freshRoleNames($colleague, $tenant))->toBe(['Org Admin']);

    $this->actingAs($owner)
        ->post("http://{$host}/roles", [
            'name' => 'Finance lead',
            'permissions' => ['read finance', 'delete user'],
        ], ['HTTP_HOST' => $host])
        ->assertSessionHasNoErrors();
});
