<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Services\Tenancy\TenantMigrator;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;

beforeEach(function () {
    Config::set('database.connections.tenant', [
        'driver' => 'sqlite',
        'database' => ':memory:',
    ]);

    Artisan::call('migrate', [
        '--path' => 'database/migrations/landlord',
        '--realpath' => true,
    ]);
});

test('tenants migrate command exists', function () {
    expect(array_key_exists('tenants:migrate', Artisan::all()))->toBeTrue();
});

test('it runs migrations for active tenants', function () {
    $tenant = Tenant::create(['name' => 'Migration Tenant', 'slug' => 'mig-tenant', 'isolation_mode' => 'shared']);

    $this->artisan('tenants:migrate')
        ->expectsOutput("Migrating tenant: {$tenant->name} ({$tenant->id})")
        ->assertExitCode(0);

    $this->assertDatabaseHas('tenant_migration_runs', [
        'tenant_id' => $tenant->id,
        'status' => 'success',
    ], 'landlord');
});

test('it logs exception on failure', function () {
    $tenant = Tenant::create(['name' => 'Fail Tenant', 'slug' => 'fail-tenant', 'isolation_mode' => 'shared']);

    $this->mock(TenantMigrator::class, function ($mock) {
        $mock->shouldReceive('migrate')
            ->once()
            ->andThrow(new Exception('Simulated Migration Failure'));
    });

    $this->artisan('tenants:migrate')
        ->assertExitCode(0);

    $this->assertDatabaseHas('tenant_migration_runs', [
        'tenant_id' => $tenant->id,
        'status' => 'failed',
        'exception' => 'Simulated Migration Failure',
    ], 'landlord');
});

test('it skips encrypted tenant without kms key', function () {
    $tenant = Tenant::create([
        'name' => 'Encrypted Tenant',
        'slug' => 'enc-tenant',
        'isolation_mode' => 'db_per_tenant',
        'encryption_at_rest' => true,
        'kms_key_ref' => null, // Missing!
        'db_driver' => 'mysql',
        'db_secret_ref' => 'secret',
    ]);

    $this->artisan('tenants:migrate')
        ->expectsOutput("Migrating tenant: {$tenant->name} ({$tenant->id})")
        ->expectsOutput("Skipping tenant {$tenant->name}: Encryption enabled but no KMS key ref.")
        ->assertExitCode(0);

    $this->assertDatabaseHas('tenant_migration_runs', [
        'tenant_id' => $tenant->id,
        'status' => 'skipped',
        'exception' => 'Missing KMS Key Ref',
    ], 'landlord');
});

test('it does not skip tenant if encryption is disabled', function () {
    $tenant = Tenant::create([
        'name' => 'Non-Encrypted Tenant',
        'slug' => 'non-enc-tenant',
        'isolation_mode' => 'db_per_tenant',
        'encryption_at_rest' => false,
        'kms_key_ref' => null,
        'db_driver' => 'mysql',
        'db_secret_ref' => 'shared_db_secret',
    ]);

    $this->mock(App\Services\Tenancy\TenantDatabaseManager::class, function ($mock) use ($tenant) {
        $mock->shouldReceive('configure')->with(Mockery::on(fn ($t) => $t->id === $tenant->id))->once();
    });

    $this->mock(TenantMigrator::class, function ($mock) {
        $mock->shouldReceive('migrate')
            ->once()
            ->andReturn(['exitCode' => 0, 'output' => 'Success']);
    });

    $this->artisan('tenants:migrate')
        ->expectsOutput("Migrating tenant: {$tenant->name} ({$tenant->id})")
        ->assertExitCode(0);

    $this->assertDatabaseHas('tenant_migration_runs', [
        'tenant_id' => $tenant->id,
        'status' => 'success',
    ], 'landlord');
});

test('it continues after failure', function () {
    $tenant1 = Tenant::create(['name' => 'Tenant 1', 'slug' => 't1', 'isolation_mode' => 'shared']);
    $tenant2 = Tenant::create(['name' => 'Tenant 2', 'slug' => 't2', 'isolation_mode' => 'shared']);

    $this->mock(TenantMigrator::class, function ($mock) {
        $mock->shouldReceive('migrate')
            ->once()
            ->andThrow(new Exception('Fail 1'));

        $mock->shouldReceive('migrate')
            ->once()
            ->andReturn(['exitCode' => 0, 'output' => 'Success']);
    });

    $this->artisan('tenants:migrate')->assertExitCode(0);

    $this->assertDatabaseHas('tenant_migration_runs', ['tenant_id' => $tenant1->id, 'status' => 'failed'], 'landlord');
    $this->assertDatabaseHas('tenant_migration_runs', ['tenant_id' => $tenant2->id, 'status' => 'success'], 'landlord');
});
