<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Session;

beforeEach(function () {
    Artisan::call('migrate', [
        '--path' => 'database/migrations/landlord',
        '--realpath' => true,
    ]);

    $this->resolver = app(TenantResolver::class);
});

test('it resolves from session', function () {
    $tenant = Tenant::create(['name' => 'Session Tenant', 'slug' => 'session-tenant', 'isolation_mode' => 'shared']);
    $user = User::factory()->create();
    $tenant->users()->attach($user->id);

    $this->actingAs($user);
    Session::put('active_tenant_id', $tenant->id);

    $resolved = $this->resolver->resolve(createRequest([], [], [], $user));

    expect($resolved->id)->toBe($tenant->id);
});

test('it resolves from header', function () {
    $tenant = Tenant::create(['name' => 'Header Tenant', 'slug' => 'header-tenant', 'isolation_mode' => 'shared']);
    $user = User::factory()->create();
    $tenant->users()->attach($user->id);

    $this->actingAs($user);
    $request = createRequest([], ['X-Tenant' => $tenant->id], [], $user);

    $resolved = $this->resolver->resolve($request);

    expect($resolved->id)->toBe($tenant->id);
});

test('it resolves from route parameter', function () {
    $tenant = Tenant::create(['name' => 'Route Tenant', 'slug' => 'route-tenant', 'isolation_mode' => 'shared']);
    $user = User::factory()->create();
    $tenant->users()->attach($user->id);

    $this->actingAs($user);
    $request = createRequest([], [], ['tenant' => $tenant->slug], $user);

    $resolved = $this->resolver->resolve($request);

    expect($resolved->id)->toBe($tenant->id);
});

test('it enforces membership', function () {
    $tenant = Tenant::create(['name' => 'Secret Tenant', 'slug' => 'secret-tenant', 'isolation_mode' => 'shared']);
    $user = User::factory()->create();
    // User NOT attached to tenant

    $this->actingAs($user);
    $request = createRequest([], ['X-Tenant' => $tenant->id], [], $user);

    $this->resolver->resolve($request);
})->throws(App\Exceptions\TenantMembershipException::class, 'You do not have access to this organization.');

function createRequest($query = [], $headers = [], $routeParams = [], ?User $user = null)
{
    $request = Request::create('/', 'GET', $query);
    foreach ($headers as $key => $value) {
        $request->headers->set($key, $value);
    }
    $request->setRouteResolver(function () use ($routeParams) {
        return new class($routeParams)
        {
            private $params;

            public function __construct($params)
            {
                $this->params = $params;
            }

            public function parameter($key)
            {
                return $this->params[$key] ?? null;
            }
        };
    });

    if ($user) {
        $request->setUserResolver(fn () => $user);
    } else {
        $request->setUserResolver(fn () => auth()->user());
    }

    return $request;
}
