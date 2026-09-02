<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\UserLoginHistory;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;

beforeEach(function () {
    Config::set('database.connections.tenant', [
        'driver' => 'sqlite',
        'database' => ':memory:',
    ]);

    Artisan::call('migrate', [
        '--path' => 'database/migrations/landlord',
        '--realpath' => true,
    ]);

    Artisan::call('migrate', [
        '--database' => 'tenant',
        '--path' => 'database/migrations/tenant',
        '--realpath' => true,
    ]);
});

test('tenant data is strictly isolated in shared db', function () {
    $tenantA = Tenant::create(['name' => 'Tenant A', 'slug' => 'tenant-a', 'isolation_mode' => 'shared']);
    $tenantB = Tenant::create(['name' => 'Tenant B', 'slug' => 'tenant-b', 'isolation_mode' => 'shared']);

    // Create data for Tenant A
    app(TenantContext::class)->setTenant($tenantA);
    UserLoginHistory::create([
        'uuid' => Str::uuid(),
        'user_id' => 1,
        'session_id' => 'sessA',
    ]);

    // Create data for Tenant B
    app(TenantContext::class)->setTenant($tenantB);
    UserLoginHistory::create([
        'uuid' => Str::uuid(),
        'user_id' => 2,
        'session_id' => 'sessB',
    ]);

    // Switch back to Tenant A and verify isolation
    app(TenantContext::class)->setTenant($tenantA);
    expect(UserLoginHistory::count())->toBe(1);
    expect(UserLoginHistory::first()->session_id)->toBe('sessA');

    // Switch to Tenant B and verify isolation
    app(TenantContext::class)->setTenant($tenantB);
    expect(UserLoginHistory::count())->toBe(1);
    expect(UserLoginHistory::first()->session_id)->toBe('sessB');
});

test('scope can be bypassed explicitly', function () {
    $tenantA = Tenant::create(['name' => 'Tenant A', 'slug' => 'tenant-a', 'isolation_mode' => 'shared']);
    $tenantB = Tenant::create(['name' => 'Tenant B', 'slug' => 'tenant-b', 'isolation_mode' => 'shared']);

    UserLoginHistory::forceCreate(['uuid' => Str::uuid(), 'tenant_id' => $tenantA->id, 'user_id' => 1, 'session_id' => 'sessA']);
    UserLoginHistory::forceCreate(['uuid' => Str::uuid(), 'tenant_id' => $tenantB->id, 'user_id' => 2, 'session_id' => 'sessB']);

    app(TenantContext::class)->setTenant($tenantA);

    // Without global scopes
    expect(UserLoginHistory::withoutGlobalScopes()->count())->toBe(2);
});
