<?php

declare(strict_types=1);

use App\Models\Permission;
use Illuminate\Support\Facades\Artisan;

beforeEach(function () {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
});

test('the permissions seeder can be re-run against a live database without disturbing it', function () {
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);

    $uuidsBefore = Permission::query()->pluck('uuid', 'name')->all();

    /*
     * Seeders do not run on deploy, so a new permission reaches production
     * only when this seeder is run there. It used to write a fresh uuid into
     * every existing permission on each run.
     */
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);

    expect(Permission::query()->pluck('uuid', 'name')->all())->toBe($uuidsBefore)
        ->and(Permission::query()->count())->toBe(count($uuidsBefore));
});

test('a permission missing from an existing database is added and granted on re-run', function () {
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);

    Permission::query()->where('name', 'read dynamic-form')->delete();
    app(Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);

    $role = App\Models\Role::findByName('Org Admin');

    expect(Permission::query()->where('name', 'read dynamic-form')->exists())->toBeTrue()
        ->and($role->hasPermissionTo('read dynamic-form'))->toBeTrue();
});
