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
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

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
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        $request->session()->regenerate();

        $invitation = $request->session()->get('organisation_invitation');
        if (is_array($invitation) && is_string($invitation['id'] ?? null) && is_string($invitation['token'] ?? null)) {
            try {
                $token = \Illuminate\Support\Facades\Crypt::decryptString($invitation['token']);
            } catch (\Illuminate\Contracts\Encryption\DecryptException) {
                $token = '';
            }
            if ($token !== '') {
                $request->session()->forget('url.intended');

                return redirect()->to($this->platformUrl('organisation-invitations.resume'));
            }
        }

        // Capture user before checking roles to avoid unnecessary queries
        $user = $request->user();

        // Superadmins should generally stay on the landlord domain unless they specifically logged in via a tenant domain
        $isSuperAdmin = $user && \Illuminate\Support\Facades\Gate::allows('access-superadmin-dashboard');

        if ($isSuperAdmin && $request->getHost() === parse_url((string) config('app.url'), PHP_URL_HOST)) {
            $request->session()->forget('active_tenant_id');
        }

        $tenant = app(\App\Services\Tenancy\TenantResolver::class)->resolve($request);

        if ($tenant === null && ! $isSuperAdmin) {
            $tenant = $user?->tenants()->get()->first(fn (Tenant $candidate): bool => app(\App\Services\Tenancy\OrganisationMemberships::class)->isActive($user, $candidate));
        }

        $subdomain = $tenant?->slug;

        if ($tenant && $subdomain !== null) {
            $request->session()->put('active_tenant_id', $tenant->id);

            return $this->redirectWithin($request, $tenant->url(RouteServiceProvider::HOME));
        }

        // Platform staff who are not superadmins (finance, marketing) belong
        // to no organisation, so the generic dashboard is not theirs.
        if (! $isSuperAdmin && $landing = app(\App\Services\Platform\PlatformStaff::class)->landingRouteFor($user)) {
            return $this->redirectWithin($request, $this->platformUrl($landing));
        }

        if ($isSuperAdmin) {
            return $this->redirectWithin($request, mb_rtrim((string) config('app.url'), '/').RouteServiceProvider::HOME);
        }

        $request->session()->forget('url.intended');

        return redirect()->to($this->platformUrl('workspaces.index'));
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): Response
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return Inertia::location(url('/login'));
    }

    private function platformUrl(string $route): string
    {
        return mb_rtrim((string) config('app.url'), '/').route($route, [], false);
    }

    private function redirectWithin(Request $request, string $destination): RedirectResponse
    {
        $intended = $request->session()->get('url.intended');
        if (is_string($intended)) {
            $expected = parse_url($destination);
            $actual = parse_url($intended);
            if (! is_array($actual) || isset($actual['user']) || isset($actual['pass'])
                || ($actual['scheme'] ?? null) !== ($expected['scheme'] ?? null)
                || ($actual['host'] ?? null) !== ($expected['host'] ?? null)
                || ($actual['port'] ?? null) !== ($expected['port'] ?? null)) {
                $request->session()->forget('url.intended');
            }
        }

        return redirect()->intended($destination);
    }
}
