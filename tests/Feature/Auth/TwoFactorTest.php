<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PragmaRX\Google2FA\Google2FA;

uses(RefreshDatabase::class);

test('user without 2fa can access dashboard normally', function () {
    // dd(\Illuminate\Support\Facades\Schema::getColumnListing('users'));
    $user = User::factory()->create();
    $user->refresh();

    App\Models\Permission::create(['name' => 'access dashboard', 'category' => 'test']);
    $user->givePermissionTo('access dashboard');

    $response = $this->actingAs($user)
        ->get('/dashboard');

    $response->assertStatus(200);
});

test('user with 2fa enabled is redirected to challenge page', function () {
    $user = User::factory()->create([
        'two_factor_secret' => 'B7S6S7S6S7S6S7S6',
        'two_factor_confirmed_at' => now(),
    ])->refresh();

    $response = $this->actingAs($user)
        ->get('/dashboard');

    $response->assertRedirect(route('two-factor.challenge'));
});

test('user can pass 2fa challenge with valid code', function () {
    $secret = 'B7S6S7S6S7S6S7S6';
    $user = User::factory()->create([
        'two_factor_secret' => $secret,
        'two_factor_confirmed_at' => now(),
    ])->refresh();

    $google2fa = new Google2FA();
    $validCode = $google2fa->getCurrentOtp($secret);

    $permission = App\Models\Permission::updateOrCreate(['name' => 'access dashboard', 'guard_name' => 'web'], ['category' => 'test']);
    $user->givePermissionTo($permission);

    $response = $this->actingAs($user)
        ->from(route('two-factor.challenge'))
        ->post(route('two-factor.challenge.store'), [
            'one_time_password' => $validCode,
        ]);

    $response->assertRedirect(route('dashboard'));
    $this->assertTrue(session()->has('google2fa'));
});

test('user fails 2fa challenge with invalid code', function () {
    $user = User::factory()->create([
        'two_factor_secret' => 'B7S6S7S6S7S6S7S6',
        'two_factor_confirmed_at' => now(),
    ])->refresh();

    $response = $this->actingAs($user)
        ->from(route('two-factor.challenge'))
        ->post(route('two-factor.challenge.store'), [
            'one_time_password' => '000000',
        ]);

    $response->assertRedirect(route('two-factor.challenge'));
    $response->assertSessionHasErrors(['one_time_password']);
});

test('tenant requiring 2fa redirects unconfigured user to profile', function () {
    $tenant = Tenant::factory()->create(['require_2fa' => true]);
    $user = User::factory()->create()->refresh();
    $user->tenants()->attach($tenant);

    // Mock the TenantContext to return this tenant
    $this->mock(App\Services\Tenancy\TenantContext::class, function ($mock) use ($tenant) {
        $mock->shouldReceive('getTenant')->andReturn($tenant);
    });

    $response = $this->actingAs($user)
        ->get('/dashboard');

    $response->assertRedirect(route('profile.index', $user));
    $response->assertSessionHas('warning');
});
