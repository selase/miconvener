<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\ConfirmPasswordRequest;
use App\Http\Requests\Tenant\ConfirmTwoFactorRequest;
use App\Http\Requests\Tenant\DisableTwoFactorRequest;
use App\Models\User;
use App\Services\Auth\TwoFactorSettings;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
        $setupSecret = $request->session()->get('two_factor_setup_secret');

        return Inertia::render('Tenant/Account/Index', [
            'accountRoutePrefix' => $request->routeIs('workspaces.*') ? 'workspaces.account' : 'tenant.account',
            'account' => [
                'name' => $user->displayName(),
                'email' => $user->email,
                'two_factor_enabled' => (bool) ($user->two_factor_secret && $user->two_factor_confirmed_at),
                'two_factor_required' => $user->tenants()->where('require_2fa', true)->exists(),
                'two_factor_confirmed_at' => $user->two_factor_confirmed_at?->format('M j, Y'),
                'has_recovery_codes' => $user->two_factor_recovery_codes !== null
                    && $user->two_factor_recovery_codes !== [],
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

        $user = $request->user();
        $recoveryCodes = $user->getConnection()->transaction(function () use ($user, $secret): array {
            $locked = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            if (! in_array($locked->status, [null, User::STATUS_ACTIVE], true)
                || (int) $locked->security_version !== (int) $user->security_version) {
                throw new AuthenticationException;
            }
            abort_if($locked->two_factor_confirmed_at !== null, 409);

            $locked->update([
                'two_factor_secret' => $secret,
                'two_factor_confirmed_at' => now(),
            ]);
            $locked->tokens()->delete();
            $codes = $locked->generateTwoFactorRecoveryCodes();
            $user->setRawAttributes($locked->getAttributes(), true);

            return $codes;
        });
        $request->session()->forget('two_factor_setup_secret');
        $request->session()->put('google2fa', ['auth_passed' => true, 'auth_time' => now()]);

        return back()
            ->with('success', 'Two-factor authentication is enabled.')
            ->with('recovery_codes', $recoveryCodes);
    }

    /**
     * Issue a fresh set of recovery codes, invalidating the old ones.
     *
     * Also the only way in for accounts that enrolled before recovery codes
     * existed: without it, anyone whose organization requires 2FA and who
     * loses their phone cannot get back in at all.
     */
    public function regenerateRecoveryCodes(ConfirmPasswordRequest $request): RedirectResponse
    {
        $codes = app(TwoFactorSettings::class)->regenerateRecoveryCodes($request->user(), $request->validated('password'));

        return back()
            ->with('success', 'New recovery codes generated. The old ones no longer work.')
            ->with('recovery_codes', $codes);
    }

    public function disable(DisableTwoFactorRequest $request): RedirectResponse
    {
        app(TwoFactorSettings::class)->disable($request->user(), $request->validated('password'));
        $request->session()->forget('google2fa');

        return back()->with('success', 'Two-factor authentication is disabled.');
    }
}
