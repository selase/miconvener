<?php

declare(strict_types=1);

use App\Exceptions\TenantMembershipException;
use App\Http\Controllers\Tenancy\TenantSwitchController;
use App\Listeners\SetTenantIdInSession;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantResolver;
use Illuminate\Auth\Events\Login;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;

it('denies stale primary ownership and inactive global identities during resolution', function (string $membership, string $status): void {
    $tenant = Tenant::factory()->create(['isolation_mode' => 'shared']);
    $user = User::factory()->create(['tenant_id' => $tenant->id, 'status' => $status]);
    if ($membership !== 'absent') {
        $user->allTenants()->attach($tenant->id, ['status' => $membership]);
    }
    $request = Request::create('/');
    $request->headers->set('X-Tenant', $tenant->id);
    $request->setUserResolver(fn () => $user);

    expect(fn () => app(TenantResolver::class)->resolve($request))->toThrow(TenantMembershipException::class);
})->with([['absent', 'active'], ['suspended', 'active'], ['revoked', 'active'], ['active', 'inactive']]);

it('keeps the host tenant ahead of session and header selection', function (): void {
    config(['session.domain' => '.miconvener.test']);
    $host = Tenant::factory()->create(['isolation_mode' => 'shared']);
    $other = Tenant::factory()->create(['isolation_mode' => 'shared']);
    $user = User::factory()->create();
    $user->tenants()->attach([$host->id, $other->id]);
    Session::put('active_tenant_id', $other->id);
    $request = Request::create('https://'.$host->slug.'.miconvener.test/');
    $request->headers->set('X-Tenant', $other->id);
    $request->setUserResolver(fn () => $user);

    expect(app(TenantResolver::class)->resolve($request)->id)->toBe($host->id);
});

it('rechecks membership instead of trusting a loaded switch relation', function (): void {
    $tenant = Tenant::factory()->create(['isolation_mode' => 'shared']);
    $user = User::factory()->create();
    $user->tenants()->attach($tenant->id);
    $user->load('tenants');
    $user->allTenants()->updateExistingPivot($tenant->id, ['status' => 'revoked']);
    $request = Request::create('/tenant/switch', 'POST', ['tenant_id' => $tenant->id]);
    $request->setUserResolver(fn () => $user);

    expect(fn () => app(TenantSwitchController::class)->switch($request))->toThrow(Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect(Session::get('active_tenant_id'))->toBeNull();
});

it('lists only safe workspace fields and preserves a session with no memberships', function (): void {
    $tenant = Tenant::factory()->create(['isolation_mode' => 'shared']);
    $user = User::factory()->create();
    $user->tenants()->attach($tenant->id);
    $request = Request::create('/tenant/my-tenants');
    $request->setUserResolver(fn () => $user);
    $controller = app(TenantSwitchController::class);

    expect($controller->index($request)->getData(true))->toBe([
        ['id' => $tenant->id, 'name' => $tenant->name, 'slug' => $tenant->slug],
    ]);
    $user->allTenants()->updateExistingPivot($tenant->id, ['status' => 'revoked']);
    Session::put('portal_marker', 'retained');
    expect($controller->index($request)->getData(true))->toBe([]);
    expect(Session::get('portal_marker'))->toBe('retained');
});

it('does not select a workspace for an inactive identity at login', function (): void {
    $tenant = Tenant::factory()->create(['isolation_mode' => 'shared']);
    $user = User::factory()->create(['status' => 'inactive']);
    $user->tenants()->attach($tenant->id);
    (new SetTenantIdInSession)->handle(new Login('web', $user, false));

    expect(Session::get('active_tenant_id'))->toBeNull();
});

it('returns an HTML membership denial without destroying unrelated authentication or proof', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);
    $request = Request::create('/console');
    $request->setLaravelSession(app('session')->driver());
    Session::put('portal_marker', 'retained');

    $response = app(Illuminate\Contracts\Debug\ExceptionHandler::class)->render($request, new TenantMembershipException);

    expect($response->getStatusCode())->toBe(403);
    $this->assertAuthenticatedAs($user);
    expect(Session::get('portal_marker'))->toBe('retained');
});

it('allows global workspace and invitation routes despite a stale selected tenant', function (string $name): void {
    $tenant = Tenant::factory()->create(['isolation_mode' => 'shared']);
    $user = User::factory()->create();
    Session::put('active_tenant_id', $tenant->id);
    $request = Request::create('/');
    $route = (new Illuminate\Routing\Route('GET', '/', fn () => null))->name($name);
    $route->bind($request);
    $request->setRouteResolver(fn () => $route);
    $request->setUserResolver(fn () => $user);

    expect(app(TenantResolver::class)->resolve($request))->toBeNull();
    expect(Session::get('active_tenant_id'))->toBe($tenant->id);
})->with(['workspaces.index', 'account-identity.edit', 'account-identity.update', 'organisation-invitations.show', 'tenant.my-tenants', 'tenant.switch', 'store.orders.index', 'store.checkout.start', 'marketplace.index']);

it('preserves independent public event giving and store access for foreign signed in staff', function (string $name): void {
    $tenant = Tenant::factory()->create(['isolation_mode' => 'shared']);
    $user = User::factory()->create();
    $request = Request::create('https://'.$tenant->slug.'.miconvener.test/');
    $route = (new Illuminate\Routing\Route('GET', '/', fn () => null))->name($name);
    $route->bind($request);
    $request->setRouteResolver(fn () => $route);
    $request->setUserResolver(fn () => $user);

    expect(app(TenantResolver::class)->resolve($request)->id)->toBe($tenant->id);
})->with(['public.events.show', 'public.giving.show', 'public.community.about']);

it('replaces a stale login selection only with an active membership', function (): void {
    $old = Tenant::factory()->create(['isolation_mode' => 'shared']);
    $current = Tenant::factory()->create(['isolation_mode' => 'shared']);
    $user = User::factory()->create(['tenant_id' => $old->id]);
    $user->allTenants()->attach($old->id, ['status' => 'revoked']);
    $user->tenants()->attach($current->id);
    Session::put('active_tenant_id', $old->id);
    Session::put('portal_marker', 'retained');

    (new SetTenantIdInSession)->handle(new Login('web', $user, false));

    expect(Session::get('active_tenant_id'))->toBe($current->id);
    expect(Session::get('portal_marker'))->toBe('retained');
});

it('keeps host login on that organisation despite a different session and intended destination', function (): void {
    $host = Tenant::factory()->create(['isolation_mode' => 'shared']);
    $other = Tenant::factory()->create(['isolation_mode' => 'shared']);
    $user = User::factory()->create();
    $user->tenants()->attach([$host->id, $other->id]);

    $this->withSession(['active_tenant_id' => $other->id, 'url.intended' => $other->url('/dashboard')])
        ->withoutMiddleware(App\Http\Middleware\PreventRequestForgery::class)
        ->post($host->url('/login'), ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect($host->url('/dashboard'))
        ->assertSessionHas('active_tenant_id', $host->id);
});

it('binds impersonated requests to the original organisation despite other memberships', function (string $selection): void {
    $bound = Tenant::factory()->create(['isolation_mode' => 'shared']);
    $foreign = Tenant::factory()->create(['isolation_mode' => 'shared']);
    $user = User::factory()->create();
    $user->tenants()->attach([$bound->id, $foreign->id]);
    Session::put('original_user', 123);
    Session::put('impersonation_tenant_id', $bound->id);
    $request = Request::create($selection === 'host' ? $foreign->url('/') : '/');
    $request->setUserResolver(fn () => $user);
    if ($selection === 'session') {
        Session::put('active_tenant_id', $foreign->id);
    } elseif ($selection === 'header') {
        $request->headers->set('X-Tenant', $foreign->id);
    }

    expect(fn () => app(TenantResolver::class)->resolve($request))->toThrow(TenantMembershipException::class);
})->with(['host', 'session', 'header']);

it('allows stopping impersonation despite a mismatched tenant selection', function (): void {
    $bound = Tenant::factory()->create(['isolation_mode' => 'shared']);
    $foreign = Tenant::factory()->create(['isolation_mode' => 'shared']);
    Session::put('original_user', 123);
    Session::put('impersonation_tenant_id', $bound->id);
    Session::put('active_tenant_id', $foreign->id);
    $request = Request::create('/stop-impersonation');
    $route = (new Illuminate\Routing\Route('GET', '/stop-impersonation', fn () => null))->name('impersonation.stop');
    $route->bind($request);
    $request->setRouteResolver(fn () => $route);

    expect(app(TenantResolver::class)->resolve($request))->toBeNull();
});

it('refuses workspace switches throughout impersonation without altering the session', function (): void {
    $tenant = Tenant::factory()->create(['isolation_mode' => 'shared']);
    $user = User::factory()->create();
    $user->tenants()->attach($tenant->id);
    Session::put('original_user', 123);
    Session::put('active_tenant_id', 'original-selection');
    $request = Request::create('/tenant/switch', 'POST', ['tenant_id' => $tenant->id]);
    $request->setUserResolver(fn () => $user);

    expect(fn () => app(TenantSwitchController::class)->switch($request))->toThrow(Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect(Session::get('active_tenant_id'))->toBe('original-selection');
});

it('resumes a trusted pending invitation on the platform after login with existing memberships', function (): void {
    $tenant = Tenant::factory()->create(['isolation_mode' => 'shared']);
    $user = User::factory()->create();
    $user->tenants()->attach($tenant->id);
    $marker = ['id' => (string) Illuminate\Support\Str::uuid(), 'token' => Illuminate\Support\Facades\Crypt::encryptString(str_repeat('a', 64))];
    $platform = mb_rtrim((string) config('app.url'), '/');

    $this->withSession(['organisation_invitation' => $marker, 'url.intended' => 'https://untrusted.example/steal'])
        ->withoutMiddleware(App\Http\Middleware\PreventRequestForgery::class)
        ->post($platform.'/login', ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect($platform.route('organisation-invitations.resume', [], false))
        ->assertSessionHas('organisation_invitation', $marker);
});

it('lands an ordinary account with zero memberships on the platform workspace list', function (): void {
    $user = User::factory()->create();
    $platform = mb_rtrim((string) config('app.url'), '/');

    $this->withSession(['url.intended' => 'https://untrusted.example/steal'])
        ->withoutMiddleware(App\Http\Middleware\PreventRequestForgery::class)
        ->post($platform.'/login', ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect($platform.route('workspaces.index', [], false));
});

it('discards a caller origin intended destination when a member signs in', function (): void {
    $tenant = Tenant::factory()->create(['isolation_mode' => 'shared']);
    $user = User::factory()->create();
    $user->tenants()->attach($tenant->id);

    $this->withSession(['url.intended' => 'https://untrusted.example/steal'])
        ->withoutMiddleware(App\Http\Middleware\PreventRequestForgery::class)
        ->post(mb_rtrim((string) config('app.url'), '/').'/login', ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect($tenant->url('/dashboard'));
});

it('preserves tenant host authority on platform account routes', function (bool $custom): void {
    $tenant = Tenant::factory()->create(['isolation_mode' => 'shared', 'custom_domain' => 'organisation.custom.test', 'custom_domain_status' => 'active']);
    $user = User::factory()->create();
    $request = Request::create($custom ? 'https://organisation.custom.test/account' : 'https://'.$tenant->slug.'.miconvener.test/account');
    $route = (new Illuminate\Routing\Route('GET', '/account', fn () => null))->name('account-identity.edit');
    $route->bind($request);
    $request->setRouteResolver(fn () => $route);
    $request->setUserResolver(fn () => $user);

    expect(fn () => app(TenantResolver::class)->resolve($request))->toThrow(TenantMembershipException::class);
})->with([false, true]);
