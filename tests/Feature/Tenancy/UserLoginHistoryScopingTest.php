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

test('user login history is scoped by tenant', function () {
    $tenant1 = Tenant::create(['name' => 'Tenant 1', 'slug' => 'tenant-1', 'isolation_mode' => 'shared']);
    $tenant2 = Tenant::create(['name' => 'Tenant 2', 'slug' => 'tenant-2', 'isolation_mode' => 'shared']);

    // Create history for tenant 1
    UserLoginHistory::forceCreate([
        'uuid' => Str::uuid(),
        'tenant_id' => $tenant1->id,
        'user_id' => 1,
        'session_id' => 'sess1',
    ]);

    // Create history for tenant 2
    UserLoginHistory::forceCreate([
        'uuid' => Str::uuid(),
        'tenant_id' => $tenant2->id,
        'user_id' => 2,
        'session_id' => 'sess2',
    ]);

    // Set context to tenant 1
    app(TenantContext::class)->setTenant($tenant1);
    expect(UserLoginHistory::count())->toBe(1);
    expect(UserLoginHistory::first()->tenant_id)->toBe($tenant1->id);

    // Set context to tenant 2
    app(TenantContext::class)->setTenant($tenant2);
    expect(UserLoginHistory::count())->toBe(1);
    expect(UserLoginHistory::first()->tenant_id)->toBe($tenant2->id);
});
