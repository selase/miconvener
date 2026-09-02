<?php

declare(strict_types=1);

use App\Models\User;
use App\Providers\RouteServiceProvider;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;

beforeEach(function () {
    refreshTenantDatabases();
});

test('email verification screen can be rendered', function () {
    $user = User::factory()->create([
        'email_verified_at' => null,
    ]);
    $tenant = setActiveTenantForTest($user);

    $this->withSession(['active_tenant_id' => $tenant->id])
        ->actingAs($user)
        ->get('/verify-email')
        ->assertStatus(200);
});

test('email can be verified', function () {
    $user = User::factory()->create([
        'email_verified_at' => null,
    ]);
    $tenant = setActiveTenantForTest($user);

    Event::fake();

    $verificationUrl = URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['id' => $user->id, 'hash' => sha1((string) $user->email)]
    );

    $this->withSession(['active_tenant_id' => $tenant->id])
        ->actingAs($user)
        ->get($verificationUrl)
        ->assertRedirect(RouteServiceProvider::HOME.'?verified=1');
});

test('email is not verified with invalid hash', function () {
    $user = User::factory()->create([
        'email_verified_at' => null,
    ]);
    $tenant = setActiveTenantForTest($user);

    $verificationUrl = URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['id' => $user->id, 'hash' => sha1('wrong-email')]
    );

    $this->withSession(['active_tenant_id' => $tenant->id])
        ->actingAs($user)
        ->get($verificationUrl);

    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
});
