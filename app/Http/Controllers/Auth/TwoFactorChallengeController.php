<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

final class TwoFactorChallengeController extends Controller
{
    public function show(Request $request): \Illuminate\Contracts\View\Factory|\Illuminate\Contracts\View\View
    {
        return view('auth.two-factor-challenge');
    }

    public function store(Request $request)
    {
        $request->validate([
            'one_time_password' => 'required',
        ]);

        $google2fa = app('pragmarx.google2fa');
        $user = $request->user();
        $submitted = (string) $request->input('one_time_password');

        // A recovery code is accepted in the same field: someone locked out of
        // their authenticator is already having a bad day, and an organization
        // that requires 2FA gives them no other way back in.
        $passed = $google2fa->verifyKey($user->two_factor_secret, $submitted)
            || $user->useTwoFactorRecoveryCode($submitted);

        if ($passed) {
            $request->session()->put('google2fa', [
                'auth_passed' => true,
                'auth_time' => now(),
            ]);

            return redirect()->intended(route('dashboard'));
        }

        return back()->withErrors(['one_time_password' => 'The provided One-Time Password is invalid.']);
    }
}
