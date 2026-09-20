<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PragmaRX\Google2FA\Google2FA;

uses(RefreshDatabase::class);

test('user without 2fa can access an authenticated page normally', function () {
    // The subject here is 2FA, not dashboard access, so this hits the
    // profile page instead: it needs only auth, no further permission, and
    // /dashboard (the landlord-domain platform dashboard) now 403s a plain
    // user regardless of 2FA -- see DashboardAccessTest.
    $user = User::factory()->create();
    $user->refresh();

    $response = $this->actingAs($user)
        ->get(route('profile.index', $user));

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

test('the 2fa challenge page uses the branded sign-in layout, not the Metronic one', function () {
    $user = User::factory()->create([
        'two_factor_secret' => 'B7S6S7S6S7S6S7S6',
        'two_factor_confirmed_at' => now(),
    ])->refresh();

    $this->actingAs($user)
        ->get(route('two-factor.challenge'))
        ->assertOk()
        ->assertSee('Authentication or recovery code')
        ->assertSee(route('two-factor.challenge.store'), false)
        ->assertDontSee('style.bundle.css', false);
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

test('tenant requiring 2fa redirects unconfigured user to account security', function () {
    $tenant = Tenant::factory()->create(['slug' => 'required-two-factor', 'require_2fa' => true]);
    $user = User::factory()->create()->refresh();
    $user->tenants()->attach($tenant);

    // TenantContext is a singleton in the container; set it directly rather than
    // mocking, since it is a plain data holder and mocking a final class is not possible.
    app(App\Services\Tenancy\TenantContext::class)->setTenant($tenant);

    $response = $this->actingAs($user)
        ->get('/dashboard');

    $response->assertRedirect(route('tenant.account', ['subdomain' => $tenant->slug]));
    $response->assertSessionHas('warning');
});

test('users cannot view another account profile', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    $this->actingAs($user)
        ->get(route('profile.index', $otherUser))
        ->assertForbidden();
});

test('tenant account shows the signed in user in the console', function () {
    $tenant = Tenant::factory()->create(['slug' => 'account-test']);
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    $user->tenants()->attach($tenant);

    $this->actingAs($user)
        ->get(route('tenant.account', ['subdomain' => $tenant->slug]))
        ->assertInertia(fn ($page) => $page
            ->component('Tenant/Account/Index')
            ->where('account.email', $user->email));
});

test('legacy tenant notification link leads to the console account page', function () {
    $tenant = Tenant::factory()->create(['slug' => 'account-redirects']);
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    $user->tenants()->attach($tenant);

    $this->actingAs($user)
        ->get(route('tenant.settings.notifications', ['subdomain' => $tenant->slug]))
        ->assertRedirect(route('tenant.account', ['subdomain' => $tenant->slug]));
});

test('tenant user can set up and confirm two factor authentication for their own account', function () {
    $tenant = Tenant::factory()->create(['slug' => 'account-two-factor']);
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    $user->tenants()->attach($tenant);

    $this->actingAs($user)
        ->post(route('tenant.account.two-factor.setup', ['subdomain' => $tenant->slug]))
        ->assertRedirect();

    $secret = session('two_factor_setup_secret');
    expect($secret)->toBeString()->not->toBeEmpty();

    $this->actingAs($user)
        ->post(route('tenant.account.two-factor.confirm', ['subdomain' => $tenant->slug]), [
            'code' => (new Google2FA())->getCurrentOtp($secret),
        ])
        ->assertSessionHasNoErrors();

    expect($user->fresh()->two_factor_secret)->toBe($secret);
    expect($user->fresh()->two_factor_confirmed_at)->not->toBeNull();
    expect(session('two_factor_setup_secret'))->toBeNull();
});

test('tenant user can disable two factor authentication with their password when it is optional', function () {
    $tenant = Tenant::factory()->create(['slug' => 'optional-two-factor', 'require_2fa' => false]);
    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
        'password' => bcrypt('correct-password'),
        'two_factor_secret' => 'B7S6S7S6S7S6S7S6',
        'two_factor_confirmed_at' => now(),
    ]);
    $user->tenants()->attach($tenant);

    $this->actingAs($user)
        ->withSession(['google2fa' => ['auth_passed' => true, 'auth_time' => now()]])
        ->post(route('tenant.account.two-factor.disable', ['subdomain' => $tenant->slug]), [
            'password' => 'correct-password',
        ])
        ->assertSessionHasNoErrors();

    expect($user->fresh()->two_factor_secret)->toBeNull();
    expect($user->fresh()->two_factor_confirmed_at)->toBeNull();
});

test('tenant user cannot disable two factor authentication when the organization requires it', function () {
    $tenant = Tenant::factory()->create(['slug' => 'required-security', 'require_2fa' => true]);
    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
        'two_factor_secret' => 'B7S6S7S6S7S6S7S6',
        'two_factor_confirmed_at' => now(),
    ]);
    $user->tenants()->attach($tenant);

    $this->actingAs($user)
        ->withSession(['google2fa' => ['auth_passed' => true, 'auth_time' => now()]])
        ->post(route('tenant.account.two-factor.disable', ['subdomain' => $tenant->slug]), [
            'password' => 'password',
        ])
        ->assertForbidden();

    expect($user->fresh()->two_factor_secret)->not->toBeNull();
});

test('confirming two factor authentication is rate limited', function () {
    // The code is six digits, so an unthrottled confirm endpoint is guessable.
    $tenant = Tenant::factory()->create(['slug' => 'throttled-security', 'require_2fa' => false]);
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    $user->tenants()->attach($tenant);

    $url = route('tenant.account.two-factor.confirm', ['subdomain' => $tenant->slug]);

    foreach (range(1, 6) as $ignored) {
        $this->actingAs($user)
            ->withSession(['two_factor_setup_secret' => 'B7S6S7S6S7S6S7S6'])
            ->post($url, ['code' => '000000'])
            ->assertStatus(302);
    }

    $this->actingAs($user)
        ->withSession(['two_factor_setup_secret' => 'B7S6S7S6S7S6S7S6'])
        ->post($url, ['code' => '000000'])
        ->assertStatus(429);

    expect($user->fresh()->two_factor_confirmed_at)->toBeNull();
});

test('enabling two factor authentication issues recovery codes once', function () {
    $tenant = Tenant::factory()->create(['slug' => 'codes-on-enable', 'require_2fa' => false]);
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    $user->tenants()->attach($tenant);

    $secret = (new Google2FA)->generateSecretKey();

    $response = $this->actingAs($user)
        ->withSession(['two_factor_setup_secret' => $secret])
        ->post(route('tenant.account.two-factor.confirm', ['subdomain' => $tenant->slug]), [
            'code' => (new Google2FA)->getCurrentOtp($secret),
        ]);

    $response->assertSessionHas('recovery_codes');
    expect($response->getSession()->get('recovery_codes'))
        ->toHaveCount(User::RECOVERY_CODE_COUNT);

    // Stored, and never readable from the page again.
    expect($user->fresh()->two_factor_recovery_codes)->toHaveCount(User::RECOVERY_CODE_COUNT);
});

test('a recovery code signs in when the authenticator is unavailable, and only once', function () {
    $user = User::factory()->create([
        'two_factor_secret' => (new Google2FA)->generateSecretKey(),
        'two_factor_confirmed_at' => now(),
    ]);
    $codes = $user->generateTwoFactorRecoveryCodes();

    $this->actingAs($user)
        ->post(route('two-factor.challenge.store'), ['one_time_password' => $codes[0]])
        ->assertRedirect();

    expect(session('google2fa.auth_passed'))->toBeTrue();
    expect($user->fresh()->two_factor_recovery_codes)
        ->toHaveCount(User::RECOVERY_CODE_COUNT - 1)
        ->not->toContain($codes[0]);

    // The same code a second time is worthless.
    $this->flushSession();
    $this->actingAs($user)
        ->post(route('two-factor.challenge.store'), ['one_time_password' => $codes[0]])
        ->assertSessionHasErrors('one_time_password');
});

test('recovery codes can be regenerated with a password, invalidating the old set', function () {
    $tenant = Tenant::factory()->create(['slug' => 'regen-codes', 'require_2fa' => true]);
    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
        'password' => bcrypt('correct-password'),
        'two_factor_secret' => (new Google2FA)->generateSecretKey(),
        'two_factor_confirmed_at' => now(),
    ]);
    $user->tenants()->attach($tenant);
    $original = $user->generateTwoFactorRecoveryCodes();

    $response = $this->actingAs($user)
        ->withSession(['google2fa' => ['auth_passed' => true, 'auth_time' => now()]])
        ->post(route('tenant.account.two-factor.recovery-codes', ['subdomain' => $tenant->slug]), [
            'password' => 'correct-password',
        ]);

    $response->assertSessionHasNoErrors();
    expect($user->fresh()->two_factor_recovery_codes)->not->toContain($original[0]);

    // A wrong password changes nothing.
    $kept = $user->fresh()->two_factor_recovery_codes;
    $this->actingAs($user)
        ->withSession(['google2fa' => ['auth_passed' => true, 'auth_time' => now()]])
        ->post(route('tenant.account.two-factor.recovery-codes', ['subdomain' => $tenant->slug]), [
            'password' => 'wrong-password',
        ])
        ->assertSessionHasErrors('password');

    expect($user->fresh()->two_factor_recovery_codes)->toBe($kept);
});

test('the account page receives the generated recovery codes so it can show them', function () {
    // Session state alone is not enough: the codes reach the page as an Inertia
    // prop, and without that share the user is told nothing and can save nothing.
    $tenant = Tenant::factory()->create(['slug' => 'codes-visible', 'require_2fa' => false]);
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    $user->tenants()->attach($tenant);
    $codes = $user->generateTwoFactorRecoveryCodes();

    $this->actingAs($user)
        ->withSession([
            'google2fa' => ['auth_passed' => true, 'auth_time' => now()],
            'recovery_codes' => $codes,
        ])
        ->get(route('tenant.account', ['subdomain' => $tenant->slug]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('flash.recovery_codes', $codes)
            ->where('account.has_recovery_codes', true));
});
