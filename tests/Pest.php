<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Session;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "uses()" function to bind a different classes or traits.
|
*/

uses(
    TestCase::class,
    Illuminate\Foundation\Testing\RefreshDatabase::class,
)->in('Feature', 'Unit');

uses(
    TestCase::class,
)->in('Infrastructure');

afterEach(function () {
    Mockery::close();
});

beforeEach(function () {
    if (! config('session.domain')) {
        config(['session.domain' => '.starterkit-v2.test']);
    }

    refreshTenantDatabases();
});

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', fn () => $this->toBe(1));

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something(): void
{
    // ..
}

function setActiveTenantForTest(?User $user = null, array $overrides = []): Tenant
{
    $tenant = Tenant::factory()->create($overrides + [
        'name' => 'Test Tenant',
        'slug' => 'test-tenant',
        'isolation_mode' => 'shared',
    ]);

    if ($user) {
        $tenant->users()->attach($user->id);
    }

    Session::put('active_tenant_id', $tenant->id);
    setPermissionsTeamId($tenant->id);
    app(App\Services\Tenancy\TenantContext::class)->setTenant($tenant);

    return $tenant;
}

function refreshTenantDatabases(): void
{
    Config::set('database.connections.tenant', Config::get('database.connections.landlord'));

    $landlordPath = database_path('migrations/landlord');
    $tenantPath = database_path('migrations/tenant');

    Artisan::call('migrate', [
        '--database' => 'landlord',
        '--path' => $landlordPath,
        '--realpath' => true,
    ]);

    Artisan::call('migrate', [
        '--database' => 'tenant',
        '--path' => $tenantPath,
        '--realpath' => true,
    ]);
}
