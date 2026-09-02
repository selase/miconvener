<?php

declare(strict_types=1);

use App\Http\Middleware\ResolveTenant;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use App\Services\Tenancy\TenantDatabaseManager;
use App\Services\Tenancy\TenantResolver;
use App\Services\Tenancy\TenantStorageManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\HttpFoundation\Response;

beforeEach(function () {
    Artisan::call('migrate', [
        '--path' => 'database/migrations/landlord',
        '--realpath' => true,
    ]);
});

test('middleware sets permissions team id', function () {
    $tenant = Tenant::create(['name' => 'Middleware RBAC', 'slug' => 'mw-rbac', 'isolation_mode' => 'shared']);
    $user = User::factory()->create();
    $tenant->users()->attach($user->id);

    $request = Request::create('/', 'GET');
    $request->headers->set('X-Tenant', $tenant->id);
    $request->setUserResolver(fn () => $user);

    $middleware = new ResolveTenant(
        app(TenantContext::class),
        app(TenantResolver::class),
        app(TenantDatabaseManager::class),
        app(TenantStorageManager::class)
    );

    $middleware->handle($request, function ($req) use ($tenant) {
        expect(getPermissionsTeamId())->toBe($tenant->id);

        return new Response();
    });
});
