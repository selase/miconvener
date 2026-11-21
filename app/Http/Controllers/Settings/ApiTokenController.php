<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class ApiTokenController extends Controller
{
    public function index(): \Illuminate\Contracts\View\Factory|\Illuminate\Contracts\View\View
    {
        $tokens = auth()->user()->tokens()
            ->latest()
            ->get();

        return view('settings.developer.index', [
            'tokens' => $tokens,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'abilities' => 'nullable|array',
        ]);

        $abilities = $request->input('abilities', ['*']);

        $authenticated = $request->user();
        $token = $authenticated->getConnection()->transaction(function () use ($request, $abilities, $authenticated): \Laravel\Sanctum\NewAccessToken {
            $user = User::query()->whereKey($authenticated->id)->lockForUpdate()->firstOrFail();
            if (! in_array($user->status, [null, User::STATUS_ACTIVE], true)
                || (int) $user->security_version !== (int) $authenticated->security_version) {
                throw new AuthenticationException;
            }

            abort_if($user->two_factor_secret && $user->two_factor_confirmed_at
                && ! $request->session()->get('google2fa.auth_passed', false), 403);

            return $user->createToken($request->string('name')->toString(), $abilities);
        });

        return back()->with('new_token', $token->plainTextToken);
    }

    public function destroy($id)
    {
        auth()->user()->tokens()->where('id', $id)->delete();

        return back()->with('success', 'Token revoked successfully.');
    }
}
