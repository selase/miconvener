<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Tenant\AccountController;
use App\Http\Middleware\StaffAccountSecurity;
use App\Listeners\CaptureStaffSecurityVersion;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    Route::middleware(['web'])->get('/_staff-security', fn () => response()->json(['staff' => Auth::guard('web')->id()]));
    Route::middleware(['api', 'auth:sanctum'])->get('/api/_staff-security', fn () => response()->json(['ok' => true]));
});

test('password reset revokes all tokens and advances account security version', function (): void {
    $user = User::factory()->create(['remember_token' => 'old-remember-token']);
    $user->createToken('organisation-a');
    $user->createToken('organisation-b');
    $request = Request::create('/reset-password', 'POST', [
        'email' => $user->email, 'token' => Password::createToken($user),
        'password' => 'changed-password', 'password_confirmation' => 'changed-password',
    ]);
    app(NewPasswordController::class)->store($request);
    expect((int) $user->fresh()->security_version)->toBe(1)
        ->and($user->fresh()->remember_token)->not->toBe('old-remember-token')
        ->and($user->tokens()->count())->toBe(0);
});

test('sessions already authenticated in either organisation are revoked without removing portal proof', function (): void {
    $user = User::factory()->create();
    $tenants = [Tenant::factory()->create(), Tenant::factory()->create()];
    $sessions = [];
    foreach ($tenants as $tenant) {
        $user->tenants()->attach($tenant);
        $session = clone app('session.store');
        $session->setId(Illuminate\Support\Str::random(40));
        $session->replace([
            Auth::guard('web')->getName() => $user->id,
            'active_tenant_id' => $tenant->id,
            StaffAccountSecurity::SESSION_KEY => ['user_id' => (string) $user->id, 'version' => 0],
            'portal_unrelated' => ['email' => 'attendee@example.org'],
            'google2fa' => ['auth_passed' => true],
        ]);
        $session->save();
        $sessions[] = $session->getId();
    }
    $request = Request::create('/reset-password', 'POST', [
        'email' => $user->email, 'token' => Password::createToken($user),
        'password' => 'changed-password', 'password_confirmation' => 'changed-password',
    ]);
    app(NewPasswordController::class)->store($request);
    foreach ($sessions as $oldId) {
        Auth::forgetGuards();
        $this->withCookie(config('session.cookie'), $oldId)->get('/_staff-security')->assertRedirect(route('login'))
            ->assertSessionHas('portal_unrelated.email', 'attendee@example.org')
            ->assertSessionMissing('google2fa')->assertSessionMissing(StaffAccountSecurity::SESSION_KEY);
        expect(app('session.store')->getHandler()->read($oldId))->toBe('');
        Auth::forgetGuards();
        $this->withCookie(config('session.cookie'), $oldId)->get('/_staff-security')->assertJson(['staff' => null]);
    }
});

test('legacy sessions after a security change are not lazily assigned a new version', function (): void {
    $user = User::factory()->create();
    $user->increment('security_version');
    $this->actingAs($user)->withSession(['portal_unrelated' => true])->get('/_staff-security')
        ->assertRedirect(route('login'))->assertSessionHas('portal_unrelated', true);
});

test('login captures the authenticated model version and clears previous two factor proof', function (): void {
    $user = User::factory()->create();
    request()->setLaravelSession(app('session.store'));
    session()->put('google2fa', ['auth_passed' => true]);
    app(CaptureStaffSecurityVersion::class)->handle(new Login('web', $user, false));
    expect(session(StaffAccountSecurity::SESSION_KEY))->toBe(['user_id' => (string) $user->id, 'version' => 0])
        ->and(session()->has('google2fa'))->toBeFalse();
    $user->increment('security_version');
    $this->actingAs($user)->get('/_staff-security')->assertRedirect(route('login'));
});

test('current staff sessions pass but fresh global inactive status is enforced', function (): void {
    $user = User::factory()->create();
    $epoch = ['user_id' => (string) $user->id, 'version' => 0];
    $this->actingAs($user)->withSession([StaffAccountSecurity::SESSION_KEY => $epoch])->get('/_staff-security')
        ->assertJson(['staff' => $user->id]);
    User::query()->whereKey($user->id)->update(['status' => User::STATUS_INACTIVE]);
    $this->get('/_staff-security')->assertRedirect(route('login'));
});

test('attendee capability only sessions retain their attributes and identifier', function (): void {
    $this->withSession(['portal_unrelated' => true]);
    $id = session()->getId();
    session()->save();
    $this->withCookie(config('session.cookie'), $id)->get('/_staff-security')
        ->assertOk()->assertSessionHas('portal_unrelated', true);
    expect(session()->getId())->toBe($id);
});

test('inactive accounts cannot use otherwise valid personal access tokens', function (): void {
    $user = User::factory()->create(['status' => User::STATUS_INACTIVE]);
    $token = $user->createToken('api')->plainTextToken;
    Auth::forgetGuards();
    $this->withToken($token)->getJson('/api/_staff-security')->assertUnauthorized();
});

test('two factor disable considers every active organisation membership', function (string $status, int $expected): void {
    $optional = Tenant::factory()->create(['require_2fa' => false]);
    $required = Tenant::factory()->create(['require_2fa' => true]);
    $user = User::factory()->create(['two_factor_secret' => 'secret', 'two_factor_confirmed_at' => now()]);
    $user->tenants()->attach($optional);
    $user->allTenants()->attach($required, ['status' => $status]);
    Route::middleware('web')->post('/_disable-security', [AccountController::class, 'disable']);
    $response = $this->actingAs($user)->withSession([
        StaffAccountSecurity::SESSION_KEY => ['user_id' => (string) $user->id, 'version' => 0],
        'active_tenant_id' => $optional->id,
    ])->withoutMiddleware(App\Http\Middleware\PreventRequestForgery::class)
        ->post('/_disable-security', ['password' => 'password']);
    $response->assertStatus($expected);
    expect($user->fresh()->two_factor_secret)->toBe($status === 'active' ? 'secret' : null);
})->with([['active', 403], ['suspended', 302], ['revoked', 302]]);

test('legacy version zero staff sessions remain valid without blessing an epoch', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user)->get('/_staff-security')->assertJson(['staff' => $user->id])
        ->assertSessionMissing(StaffAccountSecurity::SESSION_KEY);
});

test('api password login refuses inactive identities', function (): void {
    $user = User::factory()->create(['status' => User::STATUS_INACTIVE]);
    $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password', 'device_name' => 'phone'])
        ->assertUnprocessable()->assertJsonValidationErrors('email');
    expect($user->tokens()->count())->toBe(0);
});

test('api enrolled identities must supply their authenticator code before token issuance', function (): void {
    $secret = app('pragmarx.google2fa')->generateSecretKey();
    $user = User::factory()->create(['two_factor_secret' => $secret, 'two_factor_confirmed_at' => now()]);
    $credentials = ['email' => $user->email, 'password' => 'password', 'device_name' => 'phone'];
    $this->postJson('/api/v1/auth/login', $credentials)->assertUnprocessable()->assertJsonValidationErrors('two_factor_code');
    $this->postJson('/api/v1/auth/login', $credentials + ['two_factor_code' => 'invalid'])
        ->assertUnprocessable()->assertJsonValidationErrors('two_factor_code');
    expect($user->tokens()->count())->toBe(0);
    $this->postJson('/api/v1/auth/login', $credentials + ['two_factor_code' => app('pragmarx.google2fa')->getCurrentOtp($secret)])
        ->assertOk()->assertJsonStructure(['token']);
});

test('livewire profile cannot disable two factor required by another active organisation', function (): void {
    $user = User::factory()->create(['two_factor_secret' => 'secret', 'two_factor_confirmed_at' => now()]);
    $required = Tenant::factory()->create(['require_2fa' => true]);
    $user->tenants()->attach($required);
    Livewire\Livewire::actingAs($user)->test('admin.user-profile-security', ['user' => $user])
        ->set('password', 'password')->call('disableTwoFactor')->assertForbidden();
    expect($user->fresh()->two_factor_secret)->toBe('secret');
});

test('a remembered cookie created before password reset cannot restore staff authentication', function (): void {
    $user = User::factory()->create(['remember_token' => 'remember-before-reset']);
    $guard = Auth::guard('web');
    $cookie = $user->id.'|'.$user->remember_token.'|'.$user->password;
    $name = $guard->getRecallerName();
    $this->withCookie($name, $cookie)->get('/_staff-security')->assertJson(['staff' => $user->id]);
    $request = Request::create('/reset-password', 'POST', [
        'email' => $user->email, 'token' => Password::createToken($user),
        'password' => 'changed-password', 'password_confirmation' => 'changed-password',
    ]);
    app(NewPasswordController::class)->store($request);
    session()->flush();
    Auth::forgetGuards();
    $this->withCookie($name, $cookie)->get('/_staff-security')->assertJson(['staff' => null]);
});

test('revocation destroys the persisted old staff session while retaining portal attributes', function (): void {
    $user = User::factory()->create();
    $user->increment('security_version');
    $store = app('session.store');
    $store->start();
    $oldId = $store->getId();
    $store->put(Auth::guard('web')->getName(), $user->id);
    $store->put(StaffAccountSecurity::SESSION_KEY, ['user_id' => (string) $user->id, 'version' => 0]);
    $store->put('portal_unrelated', ['email' => 'attendee@example.org']);
    $store->save();
    $this->withCookie(config('session.cookie'), $oldId)->get('/_staff-security')
        ->assertRedirect(route('login'))->assertSessionHas('portal_unrelated.email', 'attendee@example.org');
    expect($store->getId())->not->toBe($oldId)
        ->and($store->getHandler()->read($oldId))->toBe('');
});

test('api password login still works for independent mobile identities without two factor', function (): void {
    $user = User::factory()->create();
    $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password', 'device_name' => 'phone'])
        ->assertOk()->assertJsonStructure(['token', 'user']);
    expect($user->tenants()->exists())->toBeFalse();
});

test('livewire profile permits disabling when the requiring membership is inactive', function (string $status): void {
    $user = User::factory()->create(['two_factor_secret' => 'secret', 'two_factor_confirmed_at' => now()]);
    $required = Tenant::factory()->create(['require_2fa' => true]);
    $user->allTenants()->attach($required, ['status' => $status]);
    Livewire\Livewire::actingAs($user)->test('admin.user-profile-security', ['user' => $user])
        ->set('password', 'password')->call('disableTwoFactor')->assertHasNoErrors();
    expect($user->fresh()->two_factor_secret)->toBeNull();
})->with(['suspended', 'revoked']);

test('personal access tokens issued before reset no longer authenticate', function (): void {
    $user = User::factory()->create();
    $token = $user->createToken('before-reset')->plainTextToken;
    $request = Request::create('/reset-password', 'POST', [
        'email' => $user->email, 'token' => Password::createToken($user),
        'password' => 'changed-password', 'password_confirmation' => 'changed-password',
    ]);
    app(NewPasswordController::class)->store($request);
    Auth::forgetGuards();
    $this->withToken($token)->getJson('/api/_staff-security')->assertUnauthorized();
});

test('active personal access tokens do not need a browser session epoch', function (): void {
    $user = User::factory()->create();
    $user->increment('security_version');
    $token = $user->createToken('after-reset')->plainTextToken;
    Auth::forgetGuards();
    $this->withToken($token)->getJson('/api/_staff-security')->assertOk();
});

test('account security DTO reflects a requiring membership outside the current organisation', function (string $status, bool $required): void {
    $optional = Tenant::factory()->create(['require_2fa' => false]);
    $other = Tenant::factory()->create(['require_2fa' => true]);
    $user = User::factory()->create();
    $user->tenants()->attach($optional);
    $user->allTenants()->attach($other, ['status' => $status]);
    app(App\Services\Tenancy\TenantContext::class)->setTenant($optional);
    $request = Request::create('/account');
    $request->setLaravelSession(app('session.store'));
    $request->setUserResolver(fn () => $user);
    $request->headers->set('X-Inertia', 'true');
    $props = app(AccountController::class)->index($request)->toResponse($request)->getData(true)['props']['account'];
    expect($props['two_factor_required'])->toBe($required);
})->with([['active', true], ['suspended', false], ['revoked', false]]);

test('a previously working token is rejected on the next HTTP request after global deactivation', function (): void {
    $user = User::factory()->create();
    $token = $user->createToken('active-before-deactivation')->plainTextToken;
    Auth::forgetGuards();
    $this->withToken($token)->getJson('/api/_staff-security')->assertOk();
    User::query()->whereKey($user->id)->update(['status' => User::STATUS_INACTIVE]);
    Auth::forgetGuards();
    $this->withToken($token)->getJson('/api/_staff-security')->assertUnauthorized();
});

test('impersonation blocks credential creation and workspace escape routes', function (string $name): void {
    $user = User::factory()->create();
    $original = User::factory()->create();
    Route::middleware('web')->get('/_impersonation-boundary', fn () => response('escaped'))->name($name);
    $this->actingAs($user)->withSession([
        'original_user' => $original->id,
        'original_security_version' => 0,
        StaffAccountSecurity::SESSION_KEY => ['user_id' => (string) $user->id, 'version' => 0],
    ])->get('/_impersonation-boundary')->assertForbidden();
})->with(['workspaces.index', 'workspaces.create', 'workspaces.store', 'settings.developer.index', 'settings.developer.store', 'account-identity.update', 'tenant.switch']);

test('impersonation ends when the original account security changes and keeps portal attributes', function (string $change): void {
    $original = User::factory()->create();
    $target = User::factory()->create();
    $original->forceFill($change === 'version' ? ['security_version' => 1] : ['status' => User::STATUS_INACTIVE])->save();
    $this->actingAs($target)->withSession([
        'original_user' => $original->id, 'original_security_version' => 0,
        'impersonation_tenant_id' => 123, 'portal_unrelated' => true,
        StaffAccountSecurity::SESSION_KEY => ['user_id' => (string) $target->id, 'version' => 0],
    ])->get('/_staff-security')->assertRedirect(route('login'))
        ->assertSessionMissing('original_user')->assertSessionMissing('original_security_version')
        ->assertSessionMissing('impersonation_tenant_id')->assertSessionHas('portal_unrelated', true);
    $this->assertGuest('web');
})->with(['version', 'status']);

test('login listener refuses a stale or globally inactive original identity', function (string $change): void {
    $original = User::factory()->create();
    $stale = $original->fresh();
    $original->forceFill($change === 'version' ? ['security_version' => 1] : ['status' => User::STATUS_INACTIVE])->save();
    request()->setLaravelSession(app('session.store'));
    session()->put('portal_unrelated', true);
    expect(fn () => app(CaptureStaffSecurityVersion::class)->handle(new Login('web', $stale, false)))
        ->toThrow(Illuminate\Auth\AuthenticationException::class);
    expect(session()->has(StaffAccountSecurity::SESSION_KEY))->toBeFalse()
        ->and(session('portal_unrelated'))->toBeTrue();
})->with(['version', 'status']);

test('api login canonicalizes mixed case and padded email before lookup', function (): void {
    $user = User::factory()->create(['email' => 'staff@example.org']);
    $this->postJson('/api/v1/auth/login', [
        'email' => '  STAFF@Example.ORG  ', 'password' => 'password', 'device_name' => 'phone',
    ])->assertOk()->assertJsonPath('user.email', $user->email);
});

test('api registration canonicalizes email before uniqueness validation', function (): void {
    User::factory()->create(['email' => 'staff@example.org']);
    $this->postJson('/api/v1/auth/register', [
        'first_name' => 'Staff', 'last_name' => 'Member', 'email' => '  STAFF@Example.ORG  ',
        'password' => 'password123', 'device_name' => 'phone',
    ])->assertUnprocessable()->assertJsonValidationErrors('email');
    expect(User::query()->where('email', 'staff@example.org')->count())->toBe(1);
});

test('api registration stores a canonical email for an independent mobile user', function (): void {
    $this->postJson('/api/v1/auth/register', [
        'first_name' => 'Mobile', 'last_name' => 'Member', 'email' => '  MOBILE@Example.ORG  ',
        'password' => 'password123', 'device_name' => 'phone',
    ])->assertCreated()->assertJsonPath('user.email', 'mobile@example.org');
    expect(User::query()->where('email', 'mobile@example.org')->firstOrFail()->tenants()->exists())->toBeFalse();
});

test('reset password POST canonicalizes email before validating the issued token', function (): void {
    $user = User::factory()->create(['email' => 'reset@example.org', 'remember_token' => 'before-reset']);
    $user->createToken('before-reset');
    $token = Password::createToken($user);
    $this->withoutMiddleware(App\Http\Middleware\PreventRequestForgery::class)->post('/reset-password', [
        'email' => '  RESET@Example.ORG  ', 'token' => $token,
        'password' => 'new-password123', 'password_confirmation' => 'new-password123',
    ])->assertRedirect(route('login'))->assertSessionHasNoErrors();
    expect(Illuminate\Support\Facades\Hash::check('new-password123', $user->fresh()->password))->toBeTrue()
        ->and($user->fresh()->security_version)->toBe(1)
        ->and($user->fresh()->remember_token)->not->toBe('before-reset')
        ->and($user->tokens()->exists())->toBeFalse();
});

test('account two factor confirmation revokes existing tokens without changing invitation security version', function (): void {
    $user = User::factory()->create();
    $oldToken = $user->createToken('before-enrollment')->plainTextToken;
    $secret = app('pragmarx.google2fa')->generateSecretKey();
    Route::middleware('web')->post('/_confirm-security', [AccountController::class, 'confirm']);
    $this->actingAs($user)->withSession(['two_factor_setup_secret' => $secret])
        ->withoutMiddleware(App\Http\Middleware\PreventRequestForgery::class)
        ->post('/_confirm-security', ['code' => app('pragmarx.google2fa')->getCurrentOtp($secret)])
        ->assertSessionHasNoErrors()->assertSessionHas('recovery_codes');
    expect($user->fresh()->two_factor_secret)->toBe($secret)
        ->and($user->fresh()->security_version)->toBe(0)
        ->and($user->tokens()->exists())->toBeFalse();
    Auth::forgetGuards();
    session()->flush();
    $this->withToken($oldToken)->getJson('/api/_staff-security')->assertUnauthorized();
});

test('legacy livewire two factor confirmation revokes existing tokens without changing security version', function (): void {
    $user = User::factory()->create();
    $user->createToken('before-enrollment');
    $secret = app('pragmarx.google2fa')->generateSecretKey();
    Livewire\Livewire::actingAs($user)->test('admin.user-profile-security', ['user' => $user])
        ->set('secret', $secret)->set('code', app('pragmarx.google2fa')->getCurrentOtp($secret))
        ->call('confirmTwoFactor')->assertHasNoErrors();
    expect($user->fresh()->two_factor_secret)->toBe($secret)
        ->and($user->fresh()->security_version)->toBe(0)
        ->and($user->tokens()->exists())->toBeFalse();
});

test('web token issuance refuses a stale security version or newly inactive identity under the user lock', function (string $change): void {
    $user = User::factory()->create();
    $stale = $user->fresh();
    $user->forceFill($change === 'version' ? ['security_version' => 1] : ['status' => User::STATUS_INACTIVE])->save();
    $request = Request::create('/settings/developer/tokens', 'POST', ['name' => 'device']);
    $request->setLaravelSession(app('session.store'));
    $request->setUserResolver(fn () => $stale);
    expect(fn () => app(App\Http\Controllers\Settings\ApiTokenController::class)->store($request))
        ->toThrow(Illuminate\Auth\AuthenticationException::class);
    expect($user->tokens()->exists())->toBeFalse();
})->with(['version', 'status']);

test('web token issuance rechecks fresh enrollment when middleware saw an unenrolled identity', function (): void {
    $user = User::factory()->create();
    $stale = $user->fresh();
    $user->update(['two_factor_secret' => 'secret', 'two_factor_confirmed_at' => now()]);
    $request = Request::create('/settings/developer/tokens', 'POST', ['name' => 'device']);
    $request->setLaravelSession(app('session.store'));
    $request->setUserResolver(fn () => $stale);
    expect(fn () => app(App\Http\Controllers\Settings\ApiTokenController::class)->store($request))
        ->toThrow(Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect($user->tokens()->exists())->toBeFalse();
});

test('web token issuance permits an active identity with the native two factor proof', function (): void {
    $user = User::factory()->create(['two_factor_secret' => 'secret', 'two_factor_confirmed_at' => now()]);
    $request = Request::create('/settings/developer/tokens', 'POST', ['name' => 'device', 'abilities' => ['read']]);
    $request->setLaravelSession(app('session.store'));
    $request->session()->put('google2fa', ['auth_passed' => true, 'auth_time' => now()]);
    $request->setUserResolver(fn () => $user);
    app(App\Http\Controllers\Settings\ApiTokenController::class)->store($request);
    expect($user->tokens()->count())->toBe(1)
        ->and($user->tokens()->first()->abilities)->toBe(['read']);
});

test('security version rollback refuses to revive previously revoked staff credentials', function (): void {
    $user = User::factory()->create(['security_version' => 1]);
    $migration = require database_path('migrations/landlord/2026_11_20_160000_add_security_version_to_users_table.php');
    expect(fn () => $migration->down())->toThrow(RuntimeException::class)
        ->and($user->fresh()->security_version)->toBe(1);
});

test('account payload selects the named route prefix for global and tenant contexts', function (string $routeName, string $prefix): void {
    $user = User::factory()->create();
    $request = Request::create('/account');
    $request->setLaravelSession(app('session.store'));
    $request->setUserResolver(fn () => $user);
    $route = (new Illuminate\Routing\Route('GET', '/account', [AccountController::class, 'index']))->name($routeName);
    $request->setRouteResolver(fn () => $route);
    $request->headers->set('X-Inertia', 'true');
    $props = app(AccountController::class)->index($request)->toResponse($request)->getData(true)['props'];
    expect($props['accountRoutePrefix'] ?? null)->toBe($prefix)
        ->and($props['account']['two_factor_required'])->toBeFalse();
})->with([['workspaces.account', 'workspaces.account'], ['tenant.account', 'tenant.account']]);

test('MFA disabling refuses a stale account security snapshot', function (): void {
    $actor = User::factory()->create(['two_factor_secret' => 'B7S6S7S6S7S6S7S6', 'two_factor_confirmed_at' => now()]);
    User::query()->whereKey($actor->id)->increment('security_version');
    $request = App\Http\Requests\Tenant\DisableTwoFactorRequest::create('/account/two-factor', 'DELETE', ['password' => 'password']);
    $request->setUserResolver(fn () => $actor);
    $request->setValidator(validator(['password' => 'password'], ['password' => 'required']));
    $request->setLaravelSession(app('session')->driver());
    expect(fn () => app(AccountController::class)->disable($request))->toThrow(Illuminate\Auth\AuthenticationException::class);
    expect($actor->fresh()->two_factor_secret)->not->toBeNull();
});

test('recovery code regeneration refuses a stale account security snapshot', function (): void {
    $actor = User::factory()->create(['two_factor_secret' => 'B7S6S7S6S7S6S7S6', 'two_factor_confirmed_at' => now()]);
    $original = $actor->generateTwoFactorRecoveryCodes();
    User::query()->whereKey($actor->id)->increment('security_version');
    $request = App\Http\Requests\Tenant\ConfirmPasswordRequest::create('/account/two-factor/recovery-codes', 'POST', ['password' => 'password']);
    $request->setUserResolver(fn () => $actor);
    $request->setValidator(validator(['password' => 'password'], ['password' => 'required']));
    $request->setLaravelSession(app('session')->driver());
    expect(fn () => app(AccountController::class)->regenerateRecoveryCodes($request))->toThrow(Illuminate\Auth\AuthenticationException::class);
    expect($actor->fresh()->two_factor_recovery_codes)->toBe($original);
});

test('revoked staff cookies do not interrupt independently verified attendee access', function (bool $verified): void {
    $user = User::factory()->create();
    User::query()->whereKey($user->id)->increment('security_version');
    $proof = $verified ? proofFor('attendee@example.test') : [];
    $response = $this->actingAs($user)->withSession($proof + [StaffAccountSecurity::SESSION_KEY => ['user_id' => (string) $user->id, 'version' => 0]])
        ->getJson(route('attendee.my.session'));
    if ($verified) {
        $response->assertOk()->assertSessionHas(App\Services\Events\PlatformAttendeeVerification::SESSION_KEY);
    } else {
        $response->assertUnauthorized();
    }
    $this->assertGuest();
})->with([true, false]);

test('public naming cannot bypass staff authentication when invalid credentials are cleared', function (): void {
    Route::middleware(['web', 'auth'])->get('/_staff-protected-public-name', fn () => response()->json(['private' => true]))->name('public.staff-protected-check');
    $user = User::factory()->create();
    User::query()->whereKey($user->id)->increment('security_version');
    $this->actingAs($user)->getJson('/_staff-protected-public-name')->assertUnauthorized()->assertDontSee('private');
    $this->assertGuest();
});

test('a revoked selected workspace does not take over independent attendee proof', function (): void {
    $user = User::factory()->create();
    $tenant = Tenant::factory()->create();
    $user->allTenants()->attach($tenant->id, ['status' => 'revoked']);
    $this->actingAs($user)->withSession(proofFor('attendee@example.test') + ['active_tenant_id' => $tenant->id])
        ->getJson(route('attendee.my.session'))->assertOk();
});
