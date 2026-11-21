<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ApiLoginRequest;
use App\Http\Requests\Api\ApiRegisterRequest;
use App\Http\Resources\UserProfileResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

final class AuthController extends Controller
{
    /**
     * Register a new independent mobile app user (no tenant association).
     */
    public function register(ApiRegisterRequest $request): JsonResponse
    {
        $user = User::create([
            'first_name' => $request->input('first_name'),
            'last_name' => $request->input('last_name'),
            'email' => mb_strtolower((string) $request->input('email')),
            'password' => Hash::make($request->input('password')),
        ]);

        $token = $user->createToken($request->input('device_name'));

        return response()->json([
            'token' => $token->plainTextToken,
            'user' => new UserProfileResource($user),
        ], 201);
    }

    /**
     * Issue a Sanctum token from email + password + device_name.
     */
    public function login(ApiLoginRequest $request): JsonResponse
    {
        $request->ensureIsNotRateLimited();

        return (new User)->getConnection()->transaction(function () use ($request): JsonResponse {
            /** @var User|null $user */
            $user = User::query()->where('email', $request->input('email'))->lockForUpdate()->first();

            if (! $user || ! in_array($user->status, [null, User::STATUS_ACTIVE], true) || ! Hash::check($request->input('password'), $user->password)) {
                RateLimiter::hit($request->throttleKey());

                throw ValidationException::withMessages([
                    'email' => [trans('auth.failed')],
                ]);
            }

            if ($user->two_factor_secret && $user->two_factor_confirmed_at) {
                $code = (string) $request->input('two_factor_code', '');
                if ($code === '' || ! app('pragmarx.google2fa')->verifyKey($user->two_factor_secret, $code)) {
                    RateLimiter::hit($request->throttleKey());

                    throw ValidationException::withMessages([
                        'two_factor_code' => ['Enter a valid authenticator code.'],
                    ]);
                }
            }

            RateLimiter::clear($request->throttleKey());

            $token = $user->createToken($request->input('device_name'));

            return response()->json([
                'token' => $token->plainTextToken,
                'user' => new UserProfileResource($user),
            ], 200);
        });
    }

    /**
     * Revoke the current Sanctum token.
     */
    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()->currentAccessToken();
        abort_unless($token instanceof \Laravel\Sanctum\PersonalAccessToken, 400, 'Use the account menu to end this browser session.');
        $token->delete();

        return response()->json(null, 204);
    }

    /**
     * Return the authenticated user's profile with tenant, roles, and features.
     */
    public function me(Request $request): UserProfileResource
    {
        return new UserProfileResource($request->user());
    }
}
