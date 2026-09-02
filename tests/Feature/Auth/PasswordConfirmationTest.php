<?php

declare(strict_types=1);

use App\Models\User;

beforeEach(function () {
    refreshTenantDatabases();
});

test('confirm password screen can be rendered', function () {
    $user = User::factory()->create();
    $tenant = setActiveTenantForTest($user);

    $this->withSession(['active_tenant_id' => $tenant->id])
        ->actingAs($user)
        ->get('/confirm-password')
        ->assertStatus(200);
});

test('password can be confirmed', function () {
    $user = User::factory()->create();
    $tenant = setActiveTenantForTest($user);

    $this->withSession(['active_tenant_id' => $tenant->id])
        ->actingAs($user)
        ->withoutMiddleware(App\Http\Middleware\VerifyCsrfToken::class)
        ->post('/confirm-password', [
            'password' => 'password',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();
});

test('password is not confirmed with invalid password', function () {
    $user = User::factory()->create();
    $tenant = setActiveTenantForTest($user);

    $this->withSession(['active_tenant_id' => $tenant->id])
        ->actingAs($user)
        ->withoutMiddleware(App\Http\Middleware\VerifyCsrfToken::class)
        ->post('/confirm-password', [
            'password' => 'wrong-password',
        ])
        ->assertSessionHasErrors();
});
