<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;

final class NewPasswordController extends Controller
{
    /**
     * Display the password reset view.
     *
     * @return \Illuminate\View\View
     */
    public function create(Request $request): Factory|View
    {
        return view('auth.reset-password', ['request' => $request]);
    }

    /**
     * Handle an incoming new password request.
     *
     * @return RedirectResponse
     *
     * @throws ValidationException
     */
    public function store(Request $request)
    {
        $email = $request->input('email');
        if (is_string($email)) {
            $request->merge(['email' => mb_strtolower(mb_trim($email))]);
        }

        $request->validate([
            'token' => ['required'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        // Here we will attempt to reset the user's password. If it is successful we
        // will update the password on an actual user model and persist it to the
        // database. Otherwise we will parse the error and return the response.
        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user) use ($request): void {
                $securityVersion = (int) $user->security_version;
                $user->getConnection()->transaction(function () use ($user, $request, $securityVersion): void {
                    $locked = User::query()->whereKey($user->id)->lockForUpdate()->first();
                    if (! $locked
                        || $locked->email !== $request->string('email')->toString()
                        || (int) $locked->security_version !== $securityVersion
                        || ! Password::tokenExists($locked, $request->string('token')->toString())) {
                        throw ValidationException::withMessages(['email' => __(Password::INVALID_TOKEN)]);
                    }

                    Password::deleteToken($locked);
                    $locked->forceFill([
                        'password' => Hash::make($request->string('password')->toString()),
                        'remember_token' => Str::random(60),
                        'security_version' => (int) $locked->security_version + 1,
                    ])->save();
                    $locked->tokens()->delete();
                    $user->setRawAttributes($locked->getAttributes(), true);
                });

                event(new PasswordReset($user));
            }
        );

        // If the password was successfully reset, we will redirect the user back to
        // the application's home authenticated view. If there is an error we can
        // redirect them back to where they came from with their error message.
        return $status === Password::PASSWORD_RESET
                    ? to_route('login')->with('status', __($status))
                    : back()->withInput($request->only('email'))
                        ->withErrors(['email' => __($status)]);
    }
}
