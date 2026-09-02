<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use App\Models\UserLoginHistory;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Gate;
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

test('policy denies access to different tenant resource', function () {
    $tenantA = Tenant::create(['name' => 'Tenant A', 'slug' => 'tenant-a', 'isolation_mode' => 'shared']);
    $tenantB = Tenant::create(['name' => 'Tenant B', 'slug' => 'tenant-b', 'isolation_mode' => 'shared']);

    $user = User::factory()->create();
    $tenantA->users()->attach($user->id);

    // Create resource for Tenant B
    $historyB = UserLoginHistory::forceCreate([
        'uuid' => Str::uuid(),
        'tenant_id' => $tenantB->id,
        'user_id' => 999,
        'session_id' => 'sessB',
    ]);

    // Set context to Tenant A
    app(TenantContext::class)->setTenant($tenantA);
    $this->actingAs($user);

    // Verify gate denies access
    expect(Gate::allows('view', $historyB))->toBeFalse();
});

test('policy allows access to own tenant resource', function () {
    $tenantA = Tenant::create(['name' => 'Tenant A', 'slug' => 'tenant-a', 'isolation_mode' => 'shared']);

    $user = User::factory()->create();
    $tenantA->users()->attach($user->id);

    // Create resource for Tenant A
    $historyA = UserLoginHistory::forceCreate([
        'uuid' => Str::uuid(),
        'tenant_id' => $tenantA->id,
        'user_id' => $user->id,
        'session_id' => 'sessA',
    ]);

    // Set context to Tenant A
    app(TenantContext::class)->setTenant($tenantA);
    $this->actingAs($user);

    // Verify gate allows access
    expect(Gate::allows('view', $historyA))->toBeTrue();
});
