<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    refreshTenantDatabases();

    $this->tenant = setActiveTenantForTest();
});

test('reset password link screen can be rendered', function () {
    $this->withSession(['active_tenant_id' => $this->tenant->id])
        ->get('/forgot-password')
        ->assertStatus(200);
});

test('reset password link can be requested', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->withSession(['active_tenant_id' => $this->tenant->id])
        ->withoutMiddleware(App\Http\Middleware\VerifyCsrfToken::class)
        ->post('/forgot-password', ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class);
});

test('reset password screen can be rendered', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->withSession(['active_tenant_id' => $this->tenant->id])
        ->withoutMiddleware(App\Http\Middleware\VerifyCsrfToken::class)
        ->post('/forgot-password', ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class, function ($notification): true {
        $this->withSession(['active_tenant_id' => $this->tenant->id])
            ->get('/reset-password/'.$notification->token)
            ->assertStatus(200);

        return true;
    });
});

test('password can be reset with valid token', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->withSession(['active_tenant_id' => $this->tenant->id])
        ->withoutMiddleware(App\Http\Middleware\VerifyCsrfToken::class)
        ->post('/forgot-password', ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user): true {
        $this->withSession(['active_tenant_id' => $this->tenant->id])
            ->withoutMiddleware(App\Http\Middleware\VerifyCsrfToken::class)
            ->post('/reset-password', [
                'token' => $notification->token,
                'email' => $user->email,
                'password' => 'password',
                'password_confirmation' => 'password',
            ])->assertSessionHasNoErrors();

        return true;
    });
});
