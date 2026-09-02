<?php

declare(strict_types=1);

use App\Enum\TenantStatusEnum;
use App\Models\Role;
use App\Models\Tenant;
use App\Services\Tenancy\RoleDuplicationService;

beforeEach(function () {
    refreshTenantDatabases();

    $this->artisan('db:seed', ['--class' => 'RoleSeeder']);
    $this->artisan('db:seed', ['--class' => 'PermissionsSeeder']);

    $this->tenant = Tenant::create([
        'name' => 'Test Corp',
        'slug' => 'test-corp',
        'status' => TenantStatusEnum::ACTIVE,
    ]);

    $this->service = app(RoleDuplicationService::class);
});

test('it clones a system role with correct tenant_id and cloned_from_role_id', function () {
    setPermissionsTeamId(null);
    $sourceRole = Role::where('name', 'Org Admin')->whereNull('tenant_id')->firstOrFail();

    $cloned = $this->service->duplicateRole(
        $sourceRole,
        $this->tenant,
        'Custom Admin',
        ['read user', 'access dashboard'],
    );

    expect($cloned->tenant_id)->toBe($this->tenant->id);
    expect($cloned->cloned_from_role_id)->toBe($sourceRole->id);
    expect($cloned->name)->toBe('Custom Admin');
    expect($cloned->guard_name)->toBe('web');
});

test('it copies only TENANT_SAFE permissions from source', function () {
    setPermissionsTeamId(null);
    $sourceRole = Role::where('name', 'Org Admin')->whereNull('tenant_id')->firstOrFail();

    // Request both safe and unsafe permissions
    $cloned = $this->service->duplicateRole(
        $sourceRole,
        $this->tenant,
        'Filtered Role',
        ['read user', 'access dashboard', 'delete tenant', 'create setting'],
    );

    $permNames = $cloned->permissions->pluck('name')->toArray();

    // Safe permissions should be present
    expect($permNames)->toContain('read user');
    expect($permNames)->toContain('access dashboard');

    // Unsafe permissions should be filtered out
    expect($permNames)->not->toContain('delete tenant');
    expect($permNames)->not->toContain('create setting');
});

test('it generates an independent role from source', function () {
    setPermissionsTeamId(null);
    $sourceRole = Role::where('name', 'Org Admin')->whereNull('tenant_id')->firstOrFail();

    $cloned = $this->service->duplicateRole(
        $sourceRole,
        $this->tenant,
        'Independent Role',
        ['read user'],
    );

    // Modifying the clone should not affect the source
    setPermissionsTeamId($this->tenant->id);
    $cloned->syncPermissions(['access dashboard']);

    setPermissionsTeamId(null);
    $sourceRole->refresh();

    expect($cloned->permissions->pluck('name')->toArray())->toBe(['access dashboard']);
    expect($sourceRole->permissions->pluck('name')->toArray())->not->toBe(['access dashboard']);
});
