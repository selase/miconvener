<?php

declare(strict_types=1);

use App\Contracts\Secrets\SecretsProvider;
use App\Http\Middleware\ResolveTenant;
use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use App\Services\Tenancy\TenantDatabaseManager;
use App\Services\Tenancy\TenantResolver;
use App\Services\Tenancy\TenantStorageManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Session;
use Symfony\Component\HttpFoundation\Response;

beforeEach(function () {
    Artisan::call('migrate', [
        '--path' => 'database/migrations/landlord',
        '--realpath' => true,
    ]);
});

test('it resolves tenant and sets context', function () {
    $tenant = Tenant::create(['name' => 'Middleware Tenant', 'slug' => 'middleware-tenant', 'isolation_mode' => 'shared']);
    // Fallback requires session check or subdomain, but resolver now checks membership
    Session::put('active_tenant_id', $tenant->id);

    $user = App\Models\User::factory()->create();
    $tenant->users()->attach($user);

    $request = Request::create('/', 'GET');
    $request->setLaravelSession(app('session')->driver());
    $request->setUserResolver(fn () => $user);

    $middleware = new ResolveTenant(
        app(TenantContext::class),
        app(TenantResolver::class),
        app(TenantDatabaseManager::class),
        app(TenantStorageManager::class)
    );

    $response = $middleware->handle($request, function ($req) use ($tenant) {
        $context = app(TenantContext::class);
        expect($context->activeTenantId())->toBe($tenant->id);

        return new Response();
    });

    expect($response)->toBeInstanceOf(Response::class);
});

test('it configures dynamic db connection for dedicated db', function () {
    $tenant = Tenant::create([
        'name' => 'Dedicated Tenant',
        'slug' => 'dedicated-tenant',
        'isolation_mode' => 'db_per_tenant',
        'db_driver' => 'mysql',
        'db_secret_ref' => 'dedicated_db_secret',
    ]);
    Session::put('active_tenant_id', $tenant->id);

    $this->mock(SecretsProvider::class, function ($mock) {
        $mock->shouldReceive('getSecret')->with('dedicated_db_secret')->andReturn([
            'type' => 'db',
            'host' => '1.2.3.4',
            'port' => '3306',
            'database' => 'dedicated_db',
            'username' => 'dedicated_user',
            'password' => 'dedicated_pass',
        ]);
    });

    $request = Request::create('/', 'GET');
    $request->setLaravelSession(app('session')->driver());

    $user = App\Models\User::factory()->create();
    $tenant->users()->attach($user);
    $request->setUserResolver(fn () => $user);

    $middleware = new ResolveTenant(
        app(TenantContext::class),
        app(TenantResolver::class),
        app(TenantDatabaseManager::class),
        app(TenantStorageManager::class)
    );

    $response = $middleware->handle($request, function ($req) {
        expect(Config::get('database.connections.tenant.driver'))->toBe('mysql');
        expect(Config::get('database.connections.tenant.host'))->toBe('1.2.3.4');
        expect(Config::get('database.connections.tenant.database'))->toBe('dedicated_db');

        return new Response();
    });

    expect($response)->toBeInstanceOf(Response::class);
});
