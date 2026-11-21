<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

final class StaffAccountSecurity
{
    public const string SESSION_KEY = 'staff_account_security';

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return $next($request);
        }

        $fresh = User::query()->find($user->getAuthIdentifier());
        $active = $fresh && in_array($fresh->status, [null, User::STATUS_ACTIVE], true);
        $token = $user->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            if (! $active || ! $token->newQuery()->whereKey($token->getKey())->exists()) {
                throw new AuthenticationException;
            }

            return $next($request);
        }

        $epoch = $request->hasSession() ? $request->session()->get(self::SESSION_KEY) : null;
        $matches = $fresh && ($epoch === null
            ? (int) $fresh->security_version === 0
            : is_array($epoch) && ($epoch['user_id'] ?? null) === (string) $user->getAuthIdentifier()
                && ($epoch['version'] ?? null) === (int) $fresh->security_version);

        if ($request->hasSession() && $request->session()->has('original_user')) {
            $original = User::query()->find($request->session()->get('original_user'));
            $originalValid = $original && in_array($original->status, [null, User::STATUS_ACTIVE], true)
                && (int) $original->security_version === (int) $request->session()->get('original_security_version', 0);
            $active = $active && $originalValid;

            if ($active && $matches) {
                abort_if($request->routeIs('workspaces.*', 'settings.developer.*', 'account-identity.*', 'organisation-invitations.*', 'tenant.switch'), 403);
            }
        }

        if ($active && $matches) {
            return $next($request);
        }

        \App\Services\Auth\StaffSessions::logoutCurrentDevice();

        if ($request->hasSession()) {
            $request->session()->forget([
                self::SESSION_KEY, 'google2fa', 'two_factor_setup_secret',
                'auth.password_confirmed_at', 'active_tenant_id',
                'original_user', 'original_security_version', 'original_active_tenant_id', 'impersonation_tenant_id',
            ]);
            $request->session()->regenerate(true);
        }

        if ($this->hasIndependentGuestAuthority($request)) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            throw new AuthenticationException;
        }

        return redirect()->route('login');
    }

    /** Public handlers must revalidate their own provider, capability or attendee proof. */
    private function hasIndependentGuestAuthority(Request $request): bool
    {
        $route = $request->route();
        if (! $route instanceof \Illuminate\Routing\Route
            || ! $route->named('public.*', 'store.*', 'marketplace.*', 'attendee.*', 'badge-template-purchases.*', 'webhooks.*', 'web.certificates.*')) {
            return false;
        }
        foreach (app('router')->gatherRouteMiddleware($route) as $middleware) {
            if (! is_string($middleware)) {
                return false;
            }
            $class = explode(':', $middleware, 2)[0];
            if (is_a($class, \Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests::class, true)
                || is_a($class, \Illuminate\Auth\Middleware\EnsureEmailIsVerified::class, true)
                || is_a($class, \Illuminate\Auth\Middleware\Authorize::class, true)
                || is_a($class, \Illuminate\Auth\Middleware\RequirePassword::class, true)
                || str_starts_with($class, 'Spatie\\Permission\\Middleware\\')
                || str_starts_with($class, 'Spatie\\Permission\\Middlewares\\')) {
                return false;
            }
        }

        return true;
    }
}
