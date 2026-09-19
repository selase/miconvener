<?php

declare(strict_types=1);

use App\Mail\Auth\SetUpRecoveryCodesMail;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

test('it emails only the users who have two factor enabled but no recovery codes', function () {
    Mail::fake();
    $tenant = Tenant::factory()->create(['slug' => 'needs-codes']);

    $needsCodes = User::factory()->create([
        'tenant_id' => $tenant->id,
        'email' => 'needs@example.com',
        'two_factor_secret' => 'B7S6S7S6S7S6S7S6',
        'two_factor_confirmed_at' => now(),
    ]);

    $alreadyHasCodes = User::factory()->create([
        'tenant_id' => $tenant->id,
        'email' => 'has@example.com',
        'two_factor_secret' => 'B7S6S7S6S7S6S7S6',
        'two_factor_confirmed_at' => now(),
    ]);
    $alreadyHasCodes->generateTwoFactorRecoveryCodes();

    // No two-factor at all: nothing to recover, so nothing to ask for.
    User::factory()->create(['tenant_id' => $tenant->id, 'email' => 'no2fa@example.com']);

    $this->artisan('auth:prompt-recovery-codes')->assertSuccessful();

    Mail::assertQueued(SetUpRecoveryCodesMail::class, 1);
    Mail::assertQueued(
        SetUpRecoveryCodesMail::class,
        fn (SetUpRecoveryCodesMail $mail): bool => $mail->hasTo($needsCodes->email)
    );
});

test('the email carries a link and never the codes themselves', function () {
    $tenant = Tenant::factory()->create(['slug' => 'link-only', 'name' => 'Nkabom Events']);
    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
        'two_factor_secret' => 'B7S6S7S6S7S6S7S6',
        'two_factor_confirmed_at' => now(),
    ]);
    $codes = $user->generateTwoFactorRecoveryCodes();

    $rendered = (new SetUpRecoveryCodesMail(
        'Ada',
        $tenant->name,
        $tenant->url('/account'),
    ))->render();

    expect($rendered)->toContain('Nkabom Events');
    expect($rendered)->toContain($tenant->url('/account'));

    foreach ($codes as $code) {
        expect($rendered)->not->toContain($code);
    }
});

test('a dry run sends nothing', function () {
    Mail::fake();
    $tenant = Tenant::factory()->create(['slug' => 'dry-run-codes']);
    User::factory()->create([
        'tenant_id' => $tenant->id,
        'two_factor_secret' => 'B7S6S7S6S7S6S7S6',
        'two_factor_confirmed_at' => now(),
    ]);

    $this->artisan('auth:prompt-recovery-codes', ['--dry-run' => true])->assertSuccessful();

    Mail::assertNothingQueued();
});
