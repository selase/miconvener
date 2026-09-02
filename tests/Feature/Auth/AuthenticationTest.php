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

    $this->withSession(['active_tenant_id' => $tenant->id])
        ->get('/login')
        ->assertStatus(200)
        ->assertSee('QNotify')
        ->assertSee('Sign in to your QNotify account.');
});

test('users can authenticate using the login screen', function () {
    $user = User::factory()->create();
    $tenant = setActiveTenantForTest($user);

    $response = $this->withSession(['active_tenant_id' => $tenant->id])
        ->withoutMiddleware(App\Http\Middleware\VerifyCsrfToken::class)
        ->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

    $expectedUrl = 'http://'.$tenant->slug.'.starterkit-v2.test/dashboard';
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
