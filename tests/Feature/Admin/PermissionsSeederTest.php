<?php

declare(strict_types=1);

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\PermissionRegistrar;

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
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);

    $role = Role::findByName('Org Admin');

    expect(Permission::query()->where('name', 'read dynamic-form')->exists())->toBeTrue()
        ->and($role->hasPermissionTo('read dynamic-form'))->toBeTrue();
});

test('a permission an administrator removed from a built-in role stays removed on re-run', function () {
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);

    $orgAdmin = Role::findByName('Org Admin');
    $orgAdmin->revokePermissionTo('delete dynamic-form');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    /*
     * The seeder now runs on every deploy. Re-granting every default each time
     * would silently undo a deliberate change made in the admin panel.
     */
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    expect($orgAdmin->fresh()->hasPermissionTo('delete dynamic-form'))->toBeFalse()
        ->and($orgAdmin->fresh()->hasPermissionTo('read dynamic-form'))->toBeTrue();
});

test('built-in tenant roles receive the intended finance permissions', function () {
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);

    $owner = Role::findByName('Org Superadmin');
    $admin = Role::findByName('Org Admin');

    expect($owner->hasPermissionTo('read finance'))->toBeTrue()
        ->and($owner->hasPermissionTo('process refunds'))->toBeTrue()
        ->and($owner->hasPermissionTo('manage payouts'))->toBeTrue()
        ->and($owner->hasPermissionTo('manage payment settings'))->toBeTrue()
        ->and($admin->hasPermissionTo('read finance'))->toBeTrue()
        ->and($admin->hasPermissionTo('process refunds'))->toBeFalse()
        ->and($admin->hasPermissionTo('manage payouts'))->toBeFalse()
        ->and($admin->hasPermissionTo('manage payment settings'))->toBeFalse()
        ->and(Permission::TENANT_SAFE)->toContain('read finance')
        ->and(Permission::TENANT_SAFE)->not->toContain('process refunds', 'manage payouts', 'manage payment settings');
});

test('a renamed built-in role does not stop the seeder or the other roles', function () {
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);

    Role::findByName('Org Admin')->update(['name' => 'Organisation Admin']);
    Permission::query()->where('name', 'read certificate')->delete();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    // Runs during deploy: a missing role must be reported, not thrown.
    expect(Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']))->toBe(0);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    expect(Role::findByName('Org Superadmin')->hasPermissionTo('read certificate'))->toBeTrue();
});

test('every permission the code checks is one the seeder defines', function () {
    /*
     * A permission checked in code but missing from the seeder can never be
     * granted, so the feature behind it is locked for every user except the
     * global superadmin, who bypasses Gate — which is exactly how six features
     * shipped unusable without anyone testing as the superadmin noticing.
     */
    $seeder = new Database\Seeders\PermissionsSeeder;
    $reflection = new ReflectionClass($seeder);
    $defined = [];

    foreach (['modelPermissions', 'defaultPermissions'] as $method) {
        foreach ($reflection->getMethod($method)->invoke($seeder) as $permission) {
            $defined[] = $permission['name'];
        }
    }

    $pattern = '/(?:authorize|allows|denies|->can|->cannot|@can|@cannot|can:|permission:)\(?\s*[\'"]?([a-z]+(?: [a-z][a-z-]*)+)[\'"]/';
    $undefined = [];

    foreach ([app_path(), resource_path('views'), base_path('routes')] as $directory) {
        foreach (Symfony\Component\Finder\Finder::create()->files()->in($directory)->name('*.php') as $file) {
            preg_match_all($pattern, $file->getContents(), $matches);

            foreach (array_diff($matches[1], $defined) as $ability) {
                $undefined[] = "`{$ability}` in {$file->getRelativePathname()}";
            }
        }
    }

    expect(array_values(array_unique($undefined)))->toBe([]);
});

test('the cross-tenant LLM usage screen is for the application superadmin only', function () {
    $tenant = App\Models\Tenant::factory()->create(['slug' => 'acme', 'isolation_mode' => 'shared']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);

    $orgSuperadmin = App\Models\User::factory()->create(['tenant_id' => $tenant->id]);
    setPermissionsTeamId($tenant->id);
    $orgSuperadmin->assignRole('Org Superadmin');

    $this->actingAs($orgSuperadmin)->get(route('llm-usage.index'))->assertForbidden();

    $superadmin = App\Models\User::factory()->create();
    setPermissionsTeamId(null);
    $superadmin->assignRole('Superadmin');

    $this->actingAs($superadmin)->get(route('llm-usage.index'))->assertOk();
});
