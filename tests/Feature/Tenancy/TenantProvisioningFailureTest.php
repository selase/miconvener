<?php

declare(strict_types=1);

use App\Exceptions\TenantProvisioningException;
use App\Models\Tenant;
use App\Services\Tenancy\TenantProvisioner;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;

beforeEach(function (): void {
    Config::set('database.connections.tenant', [
        'driver' => 'sqlite',
        'database' => ':memory:',
    ]);

    Artisan::call('migrate', [
        '--path' => 'database/migrations/landlord',
        '--realpath' => true,
    ]);
});

test('a failing tenant migration makes tenants:migrate exit non-zero', function (): void {
    Tenant::create([
        'name' => 'Broken Migrations',
        'slug' => 'broken-migrations',
        'isolation_mode' => 'db_per_tenant',
        'db_driver' => 'pgsql',
        'settlement_mode' => Tenant::SETTLEMENT_MODE_OWN_GATEWAY,
        'status' => 'active',
        'meta' => ['database' => '/nonexistent/path/tenant.sqlite'],
    ]);

    // The tenant points at an unreachable database, so the migrator fails. The
    // command must surface that as a non-zero exit code rather than reporting
    // success, otherwise callers cannot tell a broken tenant from a good one.
    $exitCode = Artisan::call('tenants:migrate');

    expect($exitCode)->not->toBe(0);
});

test('provisioning throws when the tenant migrations fail', function (): void {
    $tenant = Tenant::create([
        'name' => 'Broken Provisioning',
        'slug' => 'broken-provisioning',
        'isolation_mode' => 'byo',
        'db_driver' => 'pgsql',
        'settlement_mode' => Tenant::SETTLEMENT_MODE_OWN_GATEWAY,
        'status' => 'active',
        'meta' => ['database' => '/nonexistent/path/tenant.sqlite'],
    ]);

    expect(fn () => app(TenantProvisioner::class)->provision($tenant))
        ->toThrow(TenantProvisioningException::class);
});

test('provisioning a shared tenant skips tenant database migrations', function (): void {
    $tenant = Tenant::create([
        'name' => 'Shared Tenant',
        'slug' => 'shared-tenant',
        'isolation_mode' => 'shared',
        'db_driver' => 'pgsql',
        'settlement_mode' => Tenant::SETTLEMENT_MODE_OWN_GATEWAY,
        'status' => 'active',
    ]);

    // A shared tenant's "tenant" connection is the landlord connection, which is
    // already migrated. Running the tenant migration path against it would be
    // both redundant and, given the dangling foreign keys in that path, fatal.
    app(TenantProvisioner::class)->provision($tenant);

    expect(
        Illuminate\Support\Facades\DB::connection('landlord')
            ->table('tenant_migration_runs')
            ->where('tenant_id', $tenant->id)
            ->count()
    )->toBe(0);
});
