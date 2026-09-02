<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class TwoFactorChallenge
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        // 1. If user has 2FA enabled, they MUST pass the challenge
        if ($user->two_factor_secret &&
            $user->two_factor_confirmed_at &&
            ! $request->session()->has('google2fa') &&
            ! $request->is('two-factor-challenge*') &&
            ! $request->is('logout')
        ) {
            return redirect()->route('two-factor.challenge');
        }

        // 2. If Tenant requires 2FA, and user hasn't set it up, redirect to profile to enable it
        // We get tenant from TenantContext
        $tenant = app(\App\Services\Tenancy\TenantContext::class)->getTenant();

        if ($tenant &&
            $tenant->require_2fa &&
            (! $user->two_factor_secret || ! $user->two_factor_confirmed_at) &&
            ! $request->is('profile*') &&
            ! $request->is('logout')
        ) {
            return redirect()->route('profile.index', $user)
                ->with('warning', 'Your organization requires Two-Factor Authentication. Please enable it to continue.');
        }

        return $next($request);
    }
}
