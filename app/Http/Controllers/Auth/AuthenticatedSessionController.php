<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\Tenant;
use App\Providers\RouteServiceProvider;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Illuminate\Support\Facades\Auth;

final class AuthenticatedSessionController extends Controller
{
    /**
     * Display the login view.
     *
     * @return \Illuminate\View\View
     */
    public function create(): Factory|View
    {
        return view('auth.login');
    }

    /**
     * Handle an incoming authentication request.
     *
     * @return RedirectResponse
     */
    public function store(LoginRequest $request)
    {
        $request->authenticate();

        $request->session()->regenerate();

        $tenantId = session('active_tenant_id');

        // Capture user before checking roles to avoid unnecessary queries
        $user = $request->user();

        // Superadmins should generally stay on the landlord domain unless they specifically logged in via a tenant domain
        $isSuperAdmin = $user && \Illuminate\Support\Facades\Gate::allows('access-superadmin-dashboard');

        if (! $tenantId && ! $isSuperAdmin) {
            $tenantId = $user?->tenants()->value('tenants.id');
        }

        $tenant = $tenantId ? Tenant::query()->find($tenantId) : null;

        $subdomain = $tenant?->slug;

        if ($tenant && $subdomain !== null) {
            $tenantDomain = str_replace('://', '://'.$subdomain.'.', config('app.url'));

            return redirect()->intended($tenantDomain.RouteServiceProvider::HOME);
        }

        return redirect()->intended(RouteServiceProvider::HOME);
    }

    /**
     * Destroy an authenticated session.
     *
     * @return RedirectResponse
     */
    public function destroy(Request $request): Redirector|RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return redirect('/login');
    }
}
