<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\ConfirmTwoFactorRequest;
use App\Http\Requests\Tenant\DisableTwoFactorRequest;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;
use Inertia\Response;

final class AccountController extends Controller
{
    public function setup(Request $request): RedirectResponse
    {
        abort_if($request->user()->two_factor_confirmed_at !== null, 409);

        $request->session()->put('two_factor_setup_secret', app('pragmarx.google2fa')->generateSecretKey());

        return back();
    }

    public function index(Request $request): Response
    {
        $user = $request->user();
        $tenant = app(TenantContext::class)->getTenant();
        $setupSecret = $request->session()->get('two_factor_setup_secret');

        return Inertia::render('Tenant/Account/Index', [
            'account' => [
                'name' => $user->displayName(),
                'email' => $user->email,
                'two_factor_enabled' => (bool) ($user->two_factor_secret && $user->two_factor_confirmed_at),
                'two_factor_required' => (bool) $tenant?->require_2fa,
                'two_factor_confirmed_at' => $user->two_factor_confirmed_at?->format('M j, Y'),
            ],
            'setup' => $setupSecret && ! $user->two_factor_confirmed_at ? [
                'secret' => $setupSecret,
                'qr_code' => (new \PragmaRX\Google2FALaravel\Support\Authenticator($request))->getQRCodeInline(
                    config('app.name'),
                    $user->email,
                    $setupSecret
                ),
            ] : null,
        ]);
    }

    public function confirm(ConfirmTwoFactorRequest $request): RedirectResponse
    {
        $secret = $request->session()->get('two_factor_setup_secret');

        if (! $secret || ! app('pragmarx.google2fa')->verifyKey($secret, $request->validated('code'))) {
            return back()->withErrors(['code' => 'The verification code is invalid.']);
        }

        $request->user()->update([
            'two_factor_secret' => $secret,
            'two_factor_confirmed_at' => now(),
        ]);
        $request->session()->forget('two_factor_setup_secret');
        $request->session()->put('google2fa', ['auth_passed' => true, 'auth_time' => now()]);

        return back()->with('success', 'Two-factor authentication is enabled.');
    }

    public function disable(DisableTwoFactorRequest $request): RedirectResponse
    {
        $tenant = app(TenantContext::class)->getTenant();
        abort_if($tenant?->require_2fa, 403);

        if (! Hash::check($request->validated('password'), $request->user()->password)) {
            return back()->withErrors(['password' => 'The password is incorrect.']);
        }

        $request->user()->update([
            'two_factor_secret' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_recovery_codes' => null,
        ]);
        $request->session()->forget('google2fa');

        return back()->with('success', 'Two-factor authentication is disabled.');
    }
}
