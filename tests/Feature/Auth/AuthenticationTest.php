<?php

declare(strict_types=1);

use App\Models\User;
use Stevebauman\Location\Facades\Location;
use Stevebauman\Location\Position;

beforeEach(function () {
    refreshTenantDatabases();

    Location::shouldReceive('get')->andReturn(
        Position::make(['countryName' => 'Testland'])
    );
});

test('login screen can be rendered', function () {
    $tenant = setActiveTenantForTest();
    $appName = config('app.name');

    $this->withSession(['active_tenant_id' => $tenant->id])
        ->get('/login')
        ->assertStatus(200)
        ->assertSee($appName)
        ->assertSee("Sign in to your {$appName} account.");
});

test('users can authenticate using the login screen', function () {
    $user = User::factory()->create();
    $tenant = setActiveTenantForTest($user);

    $response = $this->withSession(['active_tenant_id' => $tenant->id])
        ->withoutMiddleware(App\Http\Middleware\PreventRequestForgery::class)
        ->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

    $expectedUrl = 'http://'.$tenant->slug.'.miconvener.test/dashboard';
    $response->assertRedirect($expectedUrl);
});

test('users can not authenticate with invalid password', function () {
    $user = User::factory()->create();
    $tenant = setActiveTenantForTest($user);

    $this->withSession(['active_tenant_id' => $tenant->id])
        ->post('/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

    $this->assertGuest();
});

test('central superadmin login clears a previously selected tenant', function () {
    $user = User::factory()->create();
    $tenant = setActiveTenantForTest($user);
    $this->seed(Database\Seeders\RoleSeeder::class);
    setPermissionsTeamId(null);
    $user->assignRole('Superadmin');

    $this->withSession(['active_tenant_id' => $tenant->id])
        ->withoutMiddleware(App\Http\Middleware\PreventRequestForgery::class)
        ->post('http://miconvener.test/login', [
            'email' => $user->email,
            'password' => 'password',
        ])
        ->assertRedirect('http://miconvener.test/dashboard')
        ->assertSessionMissing('active_tenant_id');
});

test('superadmin login on a tenant domain preserves the tenant destination', function () {
    $user = User::factory()->create();
    $tenant = setActiveTenantForTest($user);
    $this->seed(Database\Seeders\RoleSeeder::class);
    setPermissionsTeamId(null);
    $user->assignRole('Superadmin');

    $this->withSession(['active_tenant_id' => $tenant->id])
        ->withoutMiddleware(App\Http\Middleware\PreventRequestForgery::class)
        ->post('http://'.$tenant->slug.'.miconvener.test/login', [
            'email' => $user->email,
            'password' => 'password',
        ])
        ->assertRedirect('http://'.$tenant->slug.'.miconvener.test/dashboard');
});

test('central superadmin login preserves the requested management page', function () {
    $user = User::factory()->create();
    $tenant = setActiveTenantForTest($user);
    $this->seed(Database\Seeders\RoleSeeder::class);
    setPermissionsTeamId(null);
    $user->assignRole('Superadmin');

    $this->withSession([
        'active_tenant_id' => $tenant->id,
        'url.intended' => 'http://miconvener.test/tenants',
    ])
        ->withoutMiddleware(App\Http\Middleware\PreventRequestForgery::class)
        ->post('http://miconvener.test/login', [
            'email' => $user->email,
            'password' => 'password',
        ])
        ->assertRedirect('http://miconvener.test/tenants')
        ->assertSessionMissing('active_tenant_id');
});
